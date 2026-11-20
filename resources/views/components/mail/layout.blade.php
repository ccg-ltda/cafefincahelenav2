@props(['d'])
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{{ $d['title'] }}</title>
<style>
  body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  table { border-collapse: collapse; }
  img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
  a { color: #2f5a28; }
  @media only screen and (max-width: 620px) {
    .m-wrap { width: 100% !important; }
    .m-pad { padding-left: 18px !important; padding-right: 18px !important; }
    .m-stack { display: block !important; width: 100% !important; text-align: left !important; }
    .m-total { font-size: 28px !important; }
    .m-hide-sm { display: none !important; }
  }
  @media (prefers-color-scheme: dark) {
    .m-bg { background-color: #17110d !important; }
    .m-card { background-color: #241b15 !important; }
    .m-head { background-color: #2b1f18 !important; }
    .m-soft { background-color: #30251d !important; }
    .m-t { color: #f3e9da !important; }
    .m-s { color: #c2b2a0 !important; }
    .m-line { border-color: #4a3b30 !important; }
    .m-good { color: #8fcf86 !important; }
    .m-link, a { color: #a9d59f !important; }
    .m-btn { background-color: #6aa75f !important; }
    .m-btn a { color: #10200d !important; }
    .m-pill { background-color: {{ $d['tone'][2] }} !important; color: {{ $d['tone'][3] }} !important; }
  }
</style>
</head>
<body class="m-bg" style="margin:0;padding:0;background-color:#efe4d2;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#efe4d2;">{{ $d['preheader'] }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="m-bg" style="background-color:#efe4d2;">
<tr><td align="center" style="padding:24px 12px;">
  <table role="presentation" class="m-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
    <tr><td class="m-head" bgcolor="#f7ecd9" style="background-color:#f7ecd9;border-radius:14px 14px 0 0;padding:22px 32px 18px;" align="left">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td width="56" valign="middle" style="padding-right:14px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#f7e8d2" style="background-color:#f7e8d2;border-radius:28px;"><img src="{{ $d['logo_url'] }}" width="56" height="56" alt="{{ $d['brand'] }}" style="display:block;width:56px;height:56px;border-radius:28px;"></td></tr></table></td>
        <td valign="middle"><div class="m-t" style="font-family:Georgia,'Times New Roman',serif;font-size:22px;line-height:26px;font-weight:bold;color:#3a2218;">{{ $d['brand'] }}</div><div class="m-s" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:18px;color:#7a6a5c;">{{ $d['tagline'] }}</div></td>
      </tr></table>
    </td></tr>
    <tr><td height="4" style="height:4px;line-height:4px;font-size:4px;background-color:#c98a3f;">&nbsp;</td></tr>
    <tr><td class="m-card m-pad" bgcolor="#ffffff" style="background-color:#ffffff;padding:30px 32px 8px;font-family:Arial,Helvetica,sans-serif;color:#3a2218;">
      {{ $slot }}
    </td></tr>
    <tr><td class="m-card m-pad" bgcolor="#ffffff" style="background-color:#ffffff;padding:8px 32px 28px;border-radius:0 0 14px 14px;font-family:Arial,Helvetica,sans-serif;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="m-line" style="border-top:1px solid #eadfce;padding-top:18px;text-align:center;">
        <div class="m-t" style="font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:22px;color:#3a2218;">Gracias por elegir {{ $d['brand'] }}</div>
        <div class="m-s" style="font-size:12px;line-height:18px;color:#7a6a5c;padding-top:6px;">Este es un mensaje relacionado con {{ $d['audience'] === 'customer' ? 'tu pedido' : 'un pedido de la tienda' }}.@if($d['is_receipt']) Este correo es un comprobante de compra; no es una factura electrónica.@endif</div>
      </td></tr></table>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
