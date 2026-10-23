import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { describe, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { Alert } from './alert';
import { Badge } from './badge';
import { Button } from './button';
import { Card, CardHeader } from './card';
import { Dialog } from './dialog';
import { EmptyState } from './empty-state';
import { CheckboxField, SelectField, TextareaField, TextField } from './inputs';
import { Logo } from './logo';
import { Spinner } from './spinner';
import { Table, Td, Th, Tr } from './table';

describe('Button', () => {
  it('CA-03 has no accessibility violations in any variant and state', async () => {
    const { container } = render(
      <div>
        <Button>Principal</Button>
        <Button variant="accent">Destaque</Button>
        <Button variant="secondary">Secundário</Button>
        <Button variant="ghost">Discreto</Button>
        <Button variant="danger">Perigo</Button>
        <Button disabled>Desabilitado</Button>
        <Button loading>Enviando</Button>
      </div>,
    );

    expect(await axe(container)).toHaveNoViolations();
  });

  it('is a type=button by default and does not submit forms by accident', () => {
    render(<Button>Ok</Button>);

    expect(screen.getByRole('button', { name: 'Ok' })).toHaveAttribute(
      'type',
      'button',
    );
  });

  it('is disabled and busy while loading, and ignores clicks', async () => {
    const onClick = vi.fn();
    render(
      <Button loading onClick={onClick}>
        Salvar
      </Button>,
    );
    const button = screen.getByRole('button', { name: /Salvar/ });

    await userEvent.click(button);

    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-busy', 'true');
    expect(onClick).not.toHaveBeenCalled();
  });

  it('keeps a touch target of at least 44px', () => {
    render(<Button>Ok</Button>);

    expect(screen.getByRole('button').className).toContain('min-h-11');
  });
});

describe('Fields', () => {
  it('CA-04 links label, hint and error to the control', () => {
    render(
      <TextField
        label="E-mail"
        hint="Usaremos para entrar"
        error="E-mail inválido"
        required
      />,
    );
    const input = screen.getByLabelText(/E-mail/);

    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toBeRequired();
    const described = input.getAttribute('aria-describedby')!.split(' ');
    expect(described).toHaveLength(2);
    expect(document.getElementById(described[0]!)).toHaveTextContent(
      'Usaremos para entrar',
    );
    expect(document.getElementById(described[1]!)).toHaveTextContent(
      'E-mail inválido',
    );
  });

  it('CA-04 has no aria-invalid or description when there is nothing to say', () => {
    render(<TextField label="Nome" />);
    const input = screen.getByLabelText('Nome');

    expect(input).not.toHaveAttribute('aria-invalid');
    expect(input).not.toHaveAttribute('aria-describedby');
  });

  it('CA-03 has no accessibility violations (plain, with error, disabled)', async () => {
    const { container } = render(
      <form>
        <TextField label="Nome" />
        <TextField label="E-mail" error="Inválido" hint="Dica" />
        <TextField label="Telefone" disabled />
        <TextareaField label="Mensagem" />
        <SelectField label="Nicho">
          <option value="a">A</option>
        </SelectField>
        <CheckboxField label="Aceito os termos" error="Obrigatório" />
      </form>,
    );

    expect(await axe(container)).toHaveNoViolations();
  });

  it('ties the checkbox label to the checkbox so the text is clickable', async () => {
    render(<CheckboxField label="Aceito os termos" />);

    await userEvent.click(screen.getByText('Aceito os termos'));

    expect(
      screen.getByRole('checkbox', { name: 'Aceito os termos' }),
    ).toBeChecked();
  });
});

describe('Display components', () => {
  it('CA-03 has no accessibility violations', async () => {
    const { container } = render(
      <main>
        <Card>
          <CardHeader
            title="Plano"
            description="Detalhes"
            actions={<Button>Editar</Button>}
          />
          <Badge tone="success">Ativa</Badge>
          <Badge tone="warning">Teste</Badge>
          <Badge tone="danger">Suspensa</Badge>
          <Badge tone="info">Info</Badge>
          <Badge>Neutro</Badge>
        </Card>
        <Alert tone="danger" title="Erro">
          Algo deu errado
        </Alert>
        <Alert tone="success">Salvo</Alert>
        <Alert tone="warning" title="Atenção" />
        <Alert tone="info">Nota</Alert>
        <EmptyState
          title="Vazio"
          description="Nada aqui"
          action={<Button>Criar</Button>}
        />
        <Spinner />
        <Logo />
        <Table caption="Faturas">
          <thead>
            <Tr>
              <Th>Período</Th>
              <Th>Valor</Th>
            </Tr>
          </thead>
          <tbody>
            <Tr>
              <Td>Out</Td>
              <Td>R$ 50,00</Td>
            </Tr>
          </tbody>
        </Table>
      </main>,
    );

    expect(await axe(container)).toHaveNoViolations();
  });

  it('announces errors at once and other alerts politely', () => {
    render(
      <div>
        <Alert tone="danger">Erro</Alert>
        <Alert tone="warning">Alerta</Alert>
        <Alert tone="info">Nota</Alert>
        <Alert tone="success">Ok</Alert>
      </div>,
    );

    expect(screen.getAllByRole('alert')).toHaveLength(2);
    expect(screen.getAllByRole('status')).toHaveLength(2);
  });

  it('gives the table a caption for screen readers and column headers with scope', () => {
    render(
      <Table caption="Faturas">
        <thead>
          <Tr>
            <Th>Período</Th>
          </Tr>
        </thead>
      </Table>,
    );

    expect(screen.getByRole('table', { name: 'Faturas' })).toBeInTheDocument();
    expect(
      screen.getByRole('columnheader', { name: 'Período' }),
    ).toHaveAttribute('scope', 'col');
  });

  it('CA-07 shows the product name from configuration in the logo', () => {
    render(<Logo name="Meu Estúdio" />);

    expect(screen.getByText('Meu Estúdio')).toBeInTheDocument();
  });

  it('CA-07 falls back to a neutral name when none is configured', () => {
    render(<Logo />);

    expect(
      screen.getByText(process.env.NEXT_PUBLIC_APP_NAME || 'App'),
    ).toBeInTheDocument();
  });
});

function Harness({ onClose }: { onClose?: () => void }) {
  const [open, setOpen] = useState(false);

  return (
    <div>
      <button onClick={() => setOpen(true)}>Abrir</button>
      <Dialog
        open={open}
        onClose={() => {
          onClose?.();
          setOpen(false);
        }}
        title="Confirmar"
        description="Tem certeza?"
        footer={<Button>Sim</Button>}
      >
        <input aria-label="Motivo" />
      </Dialog>
    </div>
  );
}

describe('Dialog', () => {
  it('CA-03 has no accessibility violations when open', async () => {
    const user = userEvent.setup();
    const { container } = render(<Harness />);
    await user.click(screen.getByText('Abrir'));

    expect(await axe(container)).toHaveNoViolations();
  });

  it('CA-05 is labelled, modal, and moves the focus inside', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByText('Abrir'));

    const dialog = screen.getByRole('dialog', { name: 'Confirmar' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAccessibleDescription('Tem certeza?');
    expect(dialog.contains(document.activeElement)).toBe(true);
  });

  it('CA-05 keeps the focus inside with Tab and Shift+Tab', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByText('Abrir'));
    const dialog = screen.getByRole('dialog');

    for (let i = 0; i < 6; i++) {
      await user.tab();
      expect(dialog.contains(document.activeElement)).toBe(true);
    }
    for (let i = 0; i < 6; i++) {
      await user.tab({ shift: true });
      expect(dialog.contains(document.activeElement)).toBe(true);
    }
  });

  it('CA-05 closes with Esc and gives the focus back to who opened it', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<Harness onClose={onClose} />);
    const opener = screen.getByText('Abrir');
    await user.click(opener);

    await user.keyboard('{Escape}');

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();
  });

  it('closes with the close button and with a click on the backdrop', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<Harness onClose={onClose} />);

    await user.click(screen.getByText('Abrir'));
    await user.click(screen.getByRole('button', { name: 'Fechar' }));
    expect(onClose).toHaveBeenCalledTimes(1);

    await user.click(screen.getByText('Abrir'));
    await user.pointer({
      target: screen.getByRole('dialog').parentElement!,
      keys: '[MouseLeft]',
    });
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it('renders nothing while closed', () => {
    render(<Dialog open={false} onClose={() => {}} title="Escondido" />);

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});

describe('review fixes', () => {
  it('keeps the label link even when the caller passes an id', () => {
    render(<TextField label="Nome" id="meu-id" error="Erro" />);

    const input = screen.getByLabelText('Nome');
    expect(input.id).not.toBe('meu-id');
    expect(input).toHaveAttribute('aria-describedby');
  });

  it('makes a scrollable table reachable by keyboard, with a name', () => {
    render(
      <Table caption="Faturas">
        <thead />
      </Table>,
    );

    expect(screen.getByRole('region', { name: 'Faturas' })).toHaveAttribute(
      'tabindex',
      '0',
    );
  });

  it('moves the dialog focus to the content, not to the close button, and locks the page scroll', async () => {
    const user = userEvent.setup();
    const { unmount } = render(<Harness />);
    await user.click(screen.getByText('Abrir'));

    expect(screen.getByLabelText('Motivo')).toHaveFocus();
    expect(document.body.style.overflow).toBe('hidden');

    await user.keyboard('{Escape}');
    expect(document.body.style.overflow).toBe('');
    unmount();
  });
});
