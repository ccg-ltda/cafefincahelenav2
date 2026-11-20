@php $money = fn ($n) => \App\Support\OrderEmailData::money($n); @endphp
{!! $d['brand'] !!} · {!! $d['tagline'] !!}
========================================

{!! $d['title'] !!}
{!! $d['intro'] !!}

Referencia: {!! $d['reference'] !!}
Estado: {!! $d['state_label'] !!}
Total del pedido: {!! $d['total'] !!}
@if($d['is_receipt'] && $d['paid_at'])
Pagado el: {!! $d['paid_at'] !!}
@endif
@if($d['shipping_info'])

@if(!empty($d['shipping_info']['courier']))Lo lleva: {!! $d['shipping_info']['courier'] !!}
@endif
@if(!empty($d['shipping_info']['carrier']))Transportadora: {!! $d['shipping_info']['carrier'] !!}
@endif
@if(!empty($d['shipping_info']['tracking']))Número de guía: {!! $d['shipping_info']['tracking'] !!}
@endif
@endif
@if($d['audience'] === 'admin')

CLIENTE
{!! $d['contact']['name'] !!}
{!! $d['contact']['email'] !!}
{!! $d['contact']['phone'] !!}
@endif

PRODUCTOS
@foreach($d['items'] as $line)
- {!! $line['name'] !!}@if($line['presentation']) ({!! $line['presentation'] !!})@endif : {!! $line['quantity'] !!} x {!! $money($line['unit']) !!}@if($line['original']) (antes {!! $money($line['original']) !!})@endif = {!! $money($line['subtotal']) !!}

@endforeach

Subtotal: {!! $d['subtotal'] !!}
@if($d['savings'])
Descuento por promociones: -{!! $d['savings'] !!}
@endif
@if($d['coupon'])
Cupón {!! $d['coupon_code'] !!}: -{!! $d['coupon'] !!}
@endif
Envío: {!! $d['shipping'] !!}
Total: {!! $d['total'] !!}

ENTREGA
{!! $d['delivery']['address'] !!}
@if($d['delivery']['details'])
{!! $d['delivery']['details'] !!}
@endif
{!! $d['delivery']['city'] !!}@if($d['delivery']['department']), {!! $d['delivery']['department'] !!}@endif

@if($d['audience'] === 'admin' && $d['notes'])
Notas del cliente: {!! $d['notes'] !!}

@endif
{!! $d['audience'] === 'customer' ? 'Qué sigue' : 'Siguiente paso' !!}: {!! $d['next'] !!}

@if($d['audience'] === 'admin')
Ver pedido en el panel: {!! $d['panel_url'] !!}
@elseif($d['whatsapp_url'])
¿Necesitas ayuda? Escríbenos por WhatsApp: {!! $d['whatsapp_url'] !!}
@endif

--
Gracias por elegir {!! $d['brand'] !!}
Este es un mensaje relacionado con {!! $d['audience'] === 'customer' ? 'tu pedido' : 'un pedido de la tienda' !!}.
@if($d['is_receipt'])
Este correo es un comprobante de compra; no es una factura electrónica.
@endif
