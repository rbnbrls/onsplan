<?php

namespace App\Support\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Psr\Http\Message\UriInterface;

/**
 * A file handed to an MCP tool. MCP has no upload primitive, so a tool accepts the file one of three ways:
 *  - `file`: {download_url, file_id, mime_type?, file_name?} — what ChatGPT passes for a file the person attached in the chat,
 *    when the tool lists the parameter in `_meta['openai/fileParams']` (see FILE_PARAMS_META);
 *  - `file_url`: any public https URL the assistant has;
 *  - `file_base64` + `file_name`: the raw bytes, for clients that can read local files (Claude Code, Cursor).
 * The result is an UploadedFile, so it goes through the same storage code as a browser upload.
 *
 * SECURITY: fetching a URL from the server is an SSRF risk (an assistant could be talked into pointing it
 * at internal hosts). So: https only, every hop's host must resolve to public addresses only, the first
 * connection is pinned to the address we checked (no DNS-rebinding swap), max 3 redirects, and the body
 * is read up to the size cap and no further. The type is decided by sniffing the bytes, not by the name.
 */
class IncomingFile
{
    /** Put on a tool as `protected ?array $meta = IncomingFile::FILE_PARAMS_META;` so ChatGPT passes files. */
    public const FILE_PARAMS_META = ['openai/fileParams' => ['file']];

    /** What a model is told when it passed a `file` we can't read (see from()). */
    public const UNREADABLE = 'The attached file did not come through: only ChatGPT can hand a chat attachment to onsplan (as `file`). '
        .'Pass the image as file_url (a public https link) or file_base64 + file_name instead. If you have neither, '
        .'save without it and give the person the onsplan link (`url`) to add the file there.';

    /** Sniffed MIME => extension, the document/receipt types the app already accepts. */
    public const DOCUMENTS = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
    ];

    public const MAX_BYTES = 12 * 1024 * 1024; // 12 MB, same cap as the web forms

    /** Schema fragment for the three ways to pass a file. */
    public static function schema(JsonSchema $schema): array
    {
        return [
            // Exactly OpenAI's documented file-param shape (download_url + file_id required, mime_type +
            // file_name optional): ChatGPT refuses to submit an app whose `openai/fileParams` object differs.
            'file' => $schema->object([
                'download_url' => $schema->string()->required(),
                'file_id' => $schema->string()->required(),
                'mime_type' => $schema->string(),
                'file_name' => $schema->string(),
            ])->description('ChatGPT only: a file the person attached in the chat (ChatGPT fills this in itself). Other assistants (Claude, ...) cannot pass chat attachments here: leave it out and use file_url or file_base64.'),
            'file_url' => $schema->string()->description('Or: a public https URL to download the file from (e.g. the image on the recipe\'s web page).'),
            'file_base64' => $schema->string()->description('Or: the file content, base64-encoded (needs file_name). Only if you actually have the bytes (e.g. a local file in Claude Code); you cannot base64 an image you can only see.'),
            'file_name' => $schema->string()->description('File name, e.g. "deviz.pdf" (used with file_base64 or file_url).'),
        ];
    }

    /**
     * The file passed to this tool call, or null when NONE was. Throws a ValidationException on a bad one.
     * A file that was given but can't be read is always an error, never silently dropped: Claude has no way
     * to pass a chat attachment, so it filled `file` with things like a file name or {file_id} and the
     * recipe was saved without its photo and without a word (2026-10-13). The error tells the model what works.
     */
    public static function from(Request $request, array $types = self::DOCUMENTS, int $maxBytes = self::MAX_BYTES): ?UploadedFile
    {
        // One structured line per call, so a missing photo is diagnosable from the logs: WHICH shape arrived
        // and what became of it. Never the content: only keys, the URL host, lengths, the sniffed type.
        $log = self::shape($request);
        try {
            $upload = self::read($request, $types, $maxBytes);
            Log::info('mcp.file', $log + ['outcome' => $upload ? 'ok' : 'none', 'mime' => $upload?->getMimeType(), 'bytes' => $upload?->getSize()]);

            return $upload;
        } catch (ValidationException $e) {
            Log::info('mcp.file', $log + ['outcome' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** What the call carried, without its content (see from()). */
    private static function shape(Request $request): array
    {
        $file = $request->get('file');
        $url = is_array($file) ? ($file['download_url'] ?? null) : (is_string($file) ? $file : $request->get('file_url'));
        $b64 = $request->get('file_base64');

        return [
            'tool' => request()->input('params.name'),
            'user_id' => auth()->id(),
            'file' => match (true) {
                is_array($file) => 'object:'.implode(',', array_keys($file)),
                is_string($file) => 'string:'.strlen($file),
                default => $file === null ? null : gettype($file),
            },
            'url_host' => is_string($url) ? parse_url($url, PHP_URL_HOST) : null,
            'url_param' => filled($request->get('file_url')),
            'base64_length' => is_string($b64) ? strlen($b64) : null,
            'file_name' => filled($request->get('file_name')) || (is_array($file) && filled($file['file_name'] ?? null)),
        ];
    }

    private static function read(Request $request, array $types, int $maxBytes): ?UploadedFile
    {
        $file = $request->get('file');
        $url = $request->get('file_url');
        if (filled($file)) {
            // ChatGPT's {download_url}; a bare https URL in `file` is accepted too. Anything else is unreadable.
            $url = match (true) {
                is_array($file) && filled($file['download_url'] ?? null) => $file['download_url'],
                is_string($file) && str_starts_with($file, 'https://') => $file,
                default => self::fail(self::UNREADABLE),
            };
        }
        $name = (is_array($file) ? ($file['file_name'] ?? null) : null) ?? $request->get('file_name');
        $b64 = $request->get('file_base64');
        // A data URI ("data:image/jpeg;base64,...") is a common way to hand over bytes: take the payload.
        if (is_string($b64) && preg_match('/^data:[\w.+\/-]*;base64,/', $b64, $m)) {
            $b64 = substr($b64, strlen($m[0]));
        }

        if ($url) {
            $bytes = self::download((string) $url, $maxBytes);
            $name ??= basename((string) parse_url((string) $url, PHP_URL_PATH));
        } elseif ($b64) {
            $bytes = base64_decode((string) $b64, true);
            if ($bytes === false) {
                self::fail('file_base64 is not valid base64.');
            }
        } else {
            return null;
        }

        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            self::fail('The file is empty or larger than '.intdiv($maxBytes, 1024 * 1024).' MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! isset($types[$mime])) {
            self::fail("Unsupported file type ({$mime}). Allowed: ".implode(', ', array_unique($types)).'.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp');
        file_put_contents($path, $bytes);
        $base = pathinfo((string) $name, PATHINFO_FILENAME) ?: 'file';

        // test=true: the file did not arrive through PHP's upload mechanism, which is_uploaded_file() would reject.
        return new UploadedFile($path, $base.'.'.$types[$mime], $mime, null, true);
    }

    private static function download(string $url, int $maxBytes): string
    {
        $ip = self::publicAddress($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        try {
            $response = Http::timeout(30)->withOptions([
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]], // connect to the address we vetted
                'allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['https'],
                    'on_redirect' => fn ($req, $res, UriInterface $uri) => self::publicAddress((string) $uri),
                ],
            ])->get($url);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            self::fail('Could not download the file from that URL.');
        }
        if (! $response->successful()) {
            self::fail("Downloading the file failed (HTTP {$response->status()}).");
        }

        // Read at most one byte past the cap, so an oversized body is detected without loading all of it.
        $body = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (! $body->eof() && strlen($bytes) <= $maxBytes) {
            $bytes .= $body->read(65536);
        }

        return $bytes;
    }

    /** The host's address if the URL is https and every address it resolves to is public; fails otherwise. */
    private static function publicAddress(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])) {
            self::fail('file_url must be an https URL.');
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $candidate) {
            if (! filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                self::fail('That URL points to a private network address.');
            }
        }
        if ($ips === []) {
            self::fail('Could not resolve the file URL host.');
        }

        return $ips[0];
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
