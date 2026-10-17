<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background:#F5F3EE; margin:0; padding:32px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; padding:40px;">
                <tr><td>
                    <p style="font-size:24px; font-weight:600; color:#1B3A28; margin:0 0 8px;">You're invited</p>
                    <p style="font-size:15px; color:#4A6B52; line-height:1.6; margin:0 0 24px;">
                        {{ $inviter }} has invited you to join <strong>{{ $familyName }}</strong> on onsplan &mdash;
                        a calm, shared space for your family's plans, lists, and everyday moments.
                    </p>
                    <a href="{{ $acceptUrl }}"
                       style="display:inline-block; background:#2D5E3A; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px; padding:14px 24px; border-radius:12px;">
                        Accept invitation
                    </a>
                    <p style="font-size:13px; color:#7A9A80; line-height:1.6; margin:24px 0 0;">
                        If the button doesn't work, paste this link into your browser:<br>
                        <a href="{{ $acceptUrl }}" style="color:#2D5E3A;">{{ $acceptUrl }}</a>
                    </p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
