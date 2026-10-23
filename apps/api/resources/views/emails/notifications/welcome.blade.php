Olá, {{ $recipientName }}!

O seu negócio já está no {{ $product }}.@if (($data['trial_days'] ?? '0') !== '0') Você tem {{ $data['trial_days'] }} dias de teste, com acesso completo.@endif

Seu endereço: {{ $data['url'] }}
