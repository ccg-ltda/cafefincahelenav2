<x-mail.layout :d="$d">
  <h1 class="m-t" style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:26px;line-height:32px;color:#3a2218;">{{ $d['title'] }}</h1>
  <p class="m-t" style="margin:0 0 4px;font-size:15px;line-height:23px;color:#3a2218;">{{ $d['intro'] }}</p>

  <x-mail.summary :d="$d" />

  @if($d['shipping_info'])
  <div class="m-soft m-t" style="background-color:#f7ecd9;border-radius:10px;padding:12px 16px;margin:14px 0 4px;font-size:14px;line-height:22px;color:#3a2218;">
    @if(!empty($d['shipping_info']['courier']))<div><span class="m-s" style="color:#7a6a5c;">Lo lleva:</span> <strong>{{ $d['shipping_info']['courier'] }}</strong></div>@endif
    @if(!empty($d['shipping_info']['carrier']))<div><span class="m-s" style="color:#7a6a5c;">Transportadora:</span> <strong>{{ $d['shipping_info']['carrier'] }}</strong></div>@endif
    @if(!empty($d['shipping_info']['tracking']))<div><span class="m-s" style="color:#7a6a5c;">Número de guía:</span> <strong style="font-family:'Courier New',Courier,monospace;">{{ $d['shipping_info']['tracking'] }}</strong></div>@endif
  </div>
  @endif

  @if($d['audience'] === 'admin')
  <div class="m-t" style="font-family:Georgia,'Times New Roman',serif;font-size:18px;color:#3a2218;padding:18px 0 6px;">Cliente</div>
  <div class="m-t" style="font-size:14px;line-height:22px;color:#3a2218;">
    <strong>{{ $d['contact']['name'] }}</strong><br>
    <a href="mailto:{{ $d['contact']['email'] }}" class="m-link" style="color:#2f5a28;">{{ $d['contact']['email'] }}</a><br>
    {{ $d['contact']['phone'] }}
  </div>
  <x-mail.delivery :d="$d" />
  @if($d['notes'])<div class="m-soft m-t" style="background-color:#f7ecd9;border-radius:10px;padding:10px 14px;margin-top:10px;font-size:14px;line-height:21px;color:#3a2218;"><strong>Notas del cliente:</strong> {{ $d['notes'] }}</div>@endif
  @endif

  <x-mail.items :d="$d" />
  <x-mail.totals :d="$d" />

  @if($d['audience'] === 'customer')
  <x-mail.delivery :d="$d" />
  @endif

  <div class="m-soft m-t" style="background-color:#f7ecd9;border-radius:10px;padding:14px 16px;margin:22px 0 6px;font-size:14px;line-height:22px;color:#3a2218;">
    <strong>{{ $d['audience'] === 'customer' ? 'Qué sigue' : 'Siguiente paso' }}.</strong> {{ $d['next'] }}
  </div>

  @if($d['audience'] === 'admin')
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 10px;"><tr>
    <td class="m-btn" bgcolor="#2f5a28" style="background-color:#2f5a28;border-radius:8px;"><a href="{{ $d['panel_url'] }}" style="display:inline-block;padding:13px 26px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">Ver pedido en el panel</a></td>
  </tr></table>
  @elseif($d['whatsapp_url'])
  <p class="m-s" style="margin:12px 0 10px;font-size:14px;line-height:22px;color:#7a6a5c;">¿Necesitas ayuda con tu pedido? Escríbenos por <a href="{{ $d['whatsapp_url'] }}?text={{ rawurlencode('Hola, necesito ayuda con mi pedido '.$d['reference']) }}" class="m-link" style="color:#2f5a28;font-weight:bold;">WhatsApp</a>.</p>
  @endif
</x-mail.layout>
