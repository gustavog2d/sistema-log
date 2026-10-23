Olá, {{ $recipientName }}!

Recebemos o pagamento da sua assinatura{{ isset($data['amount']) ? ' ('.$data['amount'].')' : '' }}. Ela está ativa{{ isset($data['period_end']) ? ' até '.$data['period_end'] : '' }}.

Obrigado por usar o {{ $product }}.
