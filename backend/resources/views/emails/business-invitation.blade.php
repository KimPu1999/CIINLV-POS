<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CIINLV POS invitation</title>
</head>
<body style="margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #dbeafe;">
                <tr>
                    <td style="background:#0f3d91;padding:28px 32px;color:#ffffff;">
                        <div style="font-size:22px;font-weight:700;letter-spacing:.02em;">CIINLV POS</div>
                        <div style="margin-top:6px;color:#bfdbfe;font-size:13px;">Point of Sale &amp; Inventory Management System</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:36px 32px;">
                        <p style="margin:0;color:#64748b;font-size:14px;">Business administrator invitation</p>
                        <h1 style="margin:10px 0 18px;font-size:28px;line-height:1.2;color:#0f172a;">You’re invited to manage {{ $businessName }}</h1>
                        <p style="margin:0;font-size:16px;line-height:1.7;color:#475569;">This invitation was sent to <strong>{{ $recipientEmail }}</strong>. Activate your account to access the CIINLV POS business workspace.</p>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:28px 0;background:#eff6ff;border-radius:12px;">
                            <tr>
                        <td style="padding:18px 20px;color:#1e3a8a;font-size:14px;line-height:1.6;">Set your password securely through the invitation link. This link can be used once and expires on <strong>{{ $expiresAt->format('F j, Y \\a\t g:i A T') }}</strong>.</td>
                            </tr>
                        </table>
                        <table role="presentation" cellspacing="0" cellpadding="0">
                            <tr>
                                <td style="border-radius:10px;background:#2563eb;">
                                    <a href="{{ $activationUrl }}" style="display:inline-block;padding:14px 24px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;">Accept Invitation</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:26px 0 0;color:#64748b;font-size:13px;line-height:1.6;">If you were not expecting this invitation, you can safely ignore this email. For your security, do not forward the invitation link.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px;background:#f8fafc;color:#94a3b8;font-size:12px;line-height:1.5;">This is an automated message from CIINLV POS. Please do not reply to this email.</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
