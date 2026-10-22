import { health } from '@app/api-client';
import { Home, type ApiStatus } from '@/components/home';

export const dynamic = 'force-dynamic';

async function loadApiStatus(): Promise<ApiStatus> {
  try {
    const response = await health.getHealth({ cache: 'no-store' });
    return response.status === 200 ? 'ok' : 'down';
  } catch {
    return 'down';
  }
}

export default async function Page() {
  return <Home apiStatus={await loadApiStatus()} />;
}
