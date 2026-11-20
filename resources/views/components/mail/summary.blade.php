@props(['d'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="m-soft" bgcolor="#f7ecd9" style="background-color:#f7ecd9;border-radius:12px;margin:18px 0 8px;">
<tr><td style="padding:18px 20px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td class="m-stack" valign="top" style="padding-bottom:12px;">
        <div class="m-s" style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#7a6a5c;padding-bottom:4px;">Referencia</div>
        <div class="m-t" style="font-family:'Courier New',Courier,monospace;font-size:16px;font-weight:bold;color:#3a2218;">{{ $d['reference'] }}</div>
      </td>
      <td class="m-stack" valign="top" align="right" style="padding-bottom:12px;">
        <div class="m-s" style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#7a6a5c;padding-bottom:4px;">Estado</div>
        <span class="m-pill" style="display:inline-block;background-color:{{ $d['tone'][0] }};color:{{ $d['tone'][1] }};font-size:13px;font-weight:bold;line-height:18px;padding:3px 12px;border-radius:12px;">{{ $d['state_label'] }}</span>
      </td>
    </tr>
    <tr><td colspan="2" class="m-line" style="border-top:1px solid #e4d6bf;padding-top:12px;">
      <div class="m-s" style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#7a6a5c;padding-bottom:2px;">Total del pedido</div>
      <div class="m-t m-total" style="font-family:Georgia,'Times New Roman',serif;font-size:34px;line-height:40px;font-weight:bold;color:#3a2218;">{{ $d['total'] }}</div>
      @if($d['is_receipt'] && $d['paid_at'])<div class="m-s" style="font-size:12px;color:#7a6a5c;padding-top:2px;">Pagado el {{ $d['paid_at'] }}</div>@endif
    </td></tr>
  </table>
</td></tr>
</table>
