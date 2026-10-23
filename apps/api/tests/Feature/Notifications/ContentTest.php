<?php

use App\Notifications\TransactionalMail;

it('CA-11 renders every kind with the product name from configuration and no secret', function (string $kind) {
    config(['app.name' => 'Produto Provisório']);
    $mail = new TransactionalMail($kind, 'Ana', 'Estúdio Tinta Viva', [
        'url' => 'https://tintaviva.app.test', 'amount' => 'R$ 50,00', 'until' => '01/12/2026', 'reason' => 'o pagamento foi recusado', 'period_end' => '01/01/2027', 'trial_days' => '7',
    ]);

    $text = $mail->render();

    expect($text)->toContain('Ana')
        ->not->toContain('pm_fake')->not->toContain('tok_')->not->toContain('password')->not->toContain(implode('-', ['sistema', 'log']));
    expect($mail->envelope()->subject)->toContain('Estúdio Tinta Viva');
})->with(array_keys(TransactionalMail::SUBJECTS));

it('CA-11 uses the product name from configuration in the welcome', function () {
    config(['app.name' => 'Produto Provisório']);

    expect((new TransactionalMail('welcome', 'Ana', 'Estúdio', ['url' => 'https://x.app.test']))->render())->toContain('Produto Provisório');
});

it('has a subject and a view for every kind', function () {
    foreach (array_keys(TransactionalMail::SUBJECTS) as $kind) {
        expect(view()->exists('emails.notifications.'.$kind))->toBeTrue("falta a visão de {$kind}");
    }
});
