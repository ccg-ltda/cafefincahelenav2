@props(['d'])
@php $money = fn ($n) => \App\Support\OrderEmailData::money($n); @endphp
<div class="m-t" style="font-family:Georgia,'Times New Roman',serif;font-size:18px;color:#3a2218;padding:18px 0 8px;">Productos</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;line-height:20px;color:#3a2218;">
  <tr class="m-s" style="color:#7a6a5c;font-size:11px;letter-spacing:1px;text-transform:uppercase;">
    <td class="m-line" style="padding:6px 0;border-bottom:1px solid #eadfce;">Producto</td>
    <td class="m-line" align="center" style="padding:6px 6px;border-bottom:1px solid #eadfce;">Cant.</td>
    <td class="m-line m-hide-sm" align="right" style="padding:6px 6px;border-bottom:1px solid #eadfce;">Precio</td>
    <td class="m-line" align="right" style="padding:6px 0 6px 6px;border-bottom:1px solid #eadfce;">Subtotal</td>
  </tr>
  @foreach($d['items'] as $line)
  <tr class="m-t" style="color:#3a2218;">
    <td class="m-line" valign="top" style="padding:10px 0;border-bottom:1px solid #f1e8d9;"><strong>{{ $line['name'] }}</strong>@if($line['presentation'])<br><span class="m-s" style="font-size:12px;color:#7a6a5c;">{{ $line['presentation'] }}</span>@endif</td>
    <td class="m-line" valign="top" align="center" style="padding:10px 6px;border-bottom:1px solid #f1e8d9;">{{ $line['quantity'] }}</td>
    <td class="m-line m-hide-sm" valign="top" align="right" style="padding:10px 6px;border-bottom:1px solid #f1e8d9;white-space:nowrap;">@if($line['original'])<span class="m-s" style="text-decoration:line-through;color:#7a6a5c;font-size:12px;">{{ $money($line['original']) }}</span><br>@endif{{ $money($line['unit']) }}</td>
    <td class="m-line" valign="top" align="right" style="padding:10px 0 10px 6px;border-bottom:1px solid #f1e8d9;white-space:nowrap;"><strong>{{ $money($line['subtotal']) }}</strong>@if($line['original'])<br><span class="m-s" style="font-size:11px;color:#7a6a5c;">antes <span style="text-decoration:line-through;">{{ $money($line['original'] * $line['quantity']) }}</span></span>@endif</td>
  </tr>
  @endforeach
</table>
