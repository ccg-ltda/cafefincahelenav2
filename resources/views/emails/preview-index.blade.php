<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Vista previa de correos</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; background: #f7ecd9; color: #3a2218; margin: 0; padding: 24px 16px; }
  main { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
  h1 { font-family: Georgia, serif; margin-top: 0; }
  label { display: block; font-weight: bold; margin: 16px 0 6px; }
  select { width: 100%; padding: 10px; font-size: 15px; }
  ul { list-style: none; padding: 0; }
  li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid #eadfce; }
  a { color: #2f5a28; font-weight: bold; }
  .note { color: #7a6a5c; font-size: 14px; }
</style>
</head>
<body>
<main>
  <h1>Vista previa de correos</h1>
  <p class="note">Solo para mirar: aquí no se envía nada. Elige un pedido real o los datos de ejemplo y abre cada plantilla.</p>
  <label for="order">Pedido</label>
  <select id="order">
    <option value="example">Datos de ejemplo</option>
    @foreach($orders as $order)
      <option value="{{ $order->id }}" @selected($selected === (string) $order->id)>{{ $order->reference }} · {{ $order->customer_name }}</option>
    @endforeach
  </select>
  <ul>
    @foreach($types as $type => $meta)
      <li><span>{{ $meta['label'] }} <span class="note">({{ $meta['audience'] === 'customer' ? 'cliente' : 'administrador' }})</span></span>
        <span><a href="/admin/emails/preview/{{ $type }}" data-type="{{ $type }}" class="js-html">HTML</a> · <a href="/admin/emails/preview/{{ $type }}?format=text" data-type="{{ $type }}" class="js-text">Texto</a></span></li>
    @endforeach
  </ul>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
  const select = document.getElementById('order');
  const update = () => document.querySelectorAll('li a').forEach(a => {
    const url = new URL(a.getAttribute('href'), location.origin);
    url.searchParams.set('order', select.value);
    a.setAttribute('href', url.pathname + url.search);
  });
  select.addEventListener('change', update); update();
</script>
</body>
</html>
