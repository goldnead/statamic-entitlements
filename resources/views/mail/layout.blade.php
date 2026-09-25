<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    {{-- Vorschautext im Posteingang, im Körper unsichtbar. --}}
    <div style="display:none;max-height:0;overflow:hidden;">{{ $preview }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;">
                    <tr>
                        <td style="padding:32px;font-size:16px;line-height:1.6;">
                            {!! $body !!}
                        </td>
                    </tr>
                </table>
                <p style="font-size:12px;color:#71717a;margin:16px 0 0;">{{ config('app.name') }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
