@props(['d'])
<div class="m-t" style="font-family:Georgia,'Times New Roman',serif;font-size:18px;color:#3a2218;padding:22px 0 6px;">Entrega</div>
<div class="m-t" style="font-size:14px;line-height:22px;color:#3a2218;">
  {{ $d['delivery']['address'] }}@if($d['delivery']['details'])<br>{{ $d['delivery']['details'] }}@endif<br>{{ $d['delivery']['city'] }}@if($d['delivery']['department']), {{ $d['delivery']['department'] }}@endif
</div>
