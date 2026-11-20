@props(['d'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="m-t" style="font-size:14px;line-height:22px;color:#3a2218;margin-top:10px;">
  <tr><td class="m-s" style="color:#7a6a5c;">Subtotal</td><td align="right">{{ $d['subtotal'] }}</td></tr>
  @if($d['savings'])<tr><td class="m-s" style="color:#7a6a5c;">Descuento por promociones</td><td align="right" class="m-good" style="color:#2f5a28;">&minus; {{ $d['savings'] }}</td></tr>@endif
  @if($d['coupon'])<tr><td class="m-s" style="color:#7a6a5c;">Cupón <span style="font-family:'Courier New',Courier,monospace;">{{ $d['coupon_code'] }}</span></td><td align="right" class="m-good" style="color:#2f5a28;">&minus; {{ $d['coupon'] }}</td></tr>@endif
  <tr><td class="m-s" style="color:#7a6a5c;">Envío</td><td align="right">{{ $d['shipping'] }}</td></tr>
  <tr><td class="m-line" style="border-top:1px solid #eadfce;padding-top:8px;font-weight:bold;font-size:16px;">Total</td><td class="m-line" align="right" style="border-top:1px solid #eadfce;padding-top:8px;font-weight:bold;font-size:16px;">{{ $d['total'] }}</td></tr>
</table>
