import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Home } from './home';
import { t } from '@/i18n';

describe('Home', () => {
  it('renders the title from the pt-BR translation file', () => {
    render(<Home apiStatus="ok" />);
    expect(screen.getByTestId('home-title')).toHaveTextContent(t('home.title'));
  });

  it('shows the api status reported by the health endpoint', () => {
    render(<Home apiStatus="ok" />);
    expect(screen.getByTestId('home-api-status')).toHaveTextContent(
      t('home.apiOk'),
    );
  });

  it('shows an unavailable message when the api is down', () => {
    render(<Home apiStatus="down" />);
    expect(screen.getByTestId('home-api-status')).toHaveTextContent(
      t('home.apiDown'),
    );
  });
});
