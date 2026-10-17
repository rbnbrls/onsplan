<?php

namespace Modules\Calendar;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MicrosoftGraphClient
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(private CalendarIntegration $integration) {}

    public function get(string $path, array $query = []): array
    {
        return $this->request()->get($this->url($path), $query)->throw()->json();
    }

    public function post(string $path, array $body): array
    {
        return $this->request()->post($this->url($path), $body)->throw()->json();
    }

    public function patch(string $path, array $body): array
    {
        return $this->request()->patch($this->url($path), $body)->throw()->json();
    }

    public function delete(string $path): void
    {
        $this->request()->delete($this->url($path))->throw();
    }

    public function raw(string $url): array
    {
        return $this->request()->get($url)->throw()->json();
    }

    private function request(): PendingRequest
    {
        $this->ensureToken();

        return Http::withToken((string) $this->integration->access_token)
            ->acceptJson()->asJson()->timeout(20);
    }

    private function ensureToken(): void
    {
        if (filled($this->integration->access_token)
            && $this->integration->access_token_expires_at?->isFuture()) {
            return;
        }

        $refresh = $this->integration->refresh_token;
        if (blank($refresh)) {
            throw new RuntimeException('Microsoft authorization is required.');
        }

        $response = Http::asForm()->timeout(20)->post($this->tokenUrl(), [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
        ])->throw()->json();

        $this->integration->forceFill([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $refresh,
            'access_token_expires_at' => now()->addSeconds(max(60, (int) ($response['expires_in'] ?? 3600) - 60)),
            'status' => 'active',
            'last_error' => null,
        ])->save();
    }

    private function tokenUrl(): string
    {
        return 'https://login.microsoftonline.com/'.config('services.microsoft.tenant', 'common').'/oauth2/v2.0/token';
    }

    private function url(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : self::BASE.'/'.ltrim($path, '/');
    }
}
