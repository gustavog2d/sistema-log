export type Service = {
  id: string;
  name: string;
  description: string | null;
  category_id: string | null;
  duration_minutes: number;
  price_cents: number | null;
  buffer_before_minutes: number;
  buffer_after_minutes: number;
  requires_deposit: boolean;
  status: 'active' | 'archived';
  created_at: string;
  updated_at: string;
};

export type ServicePage = { data: Service[]; next_cursor: string | null };

export type ServiceCategory = {
  id: string;
  name: string;
  services_count: number;
  created_at: string;
  updated_at: string;
};

export type PresetResult = {
  niche: string;
  created: { categories: number; services: number };
  skipped: { categories: number; services: number };
};

/** `90` vira `1 h 30 min`; `45`, `45 min`; `120`, `2 h`. */
export function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  if (hours === 0) return `${rest} min`;

  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}
