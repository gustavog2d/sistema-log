Olá, {{ $recipientName }}!

Não conseguimos cobrar a sua assinatura{{ isset($data['reason']) ? ' ('.$data['reason'].')' : '' }} e por isso ela foi suspensa. Seus dados continuam guardados, mas você só consegue consultar, não alterar.

Para voltar a usar na hora, informe um novo meio de pagamento em: {{ $data['url'] }}
