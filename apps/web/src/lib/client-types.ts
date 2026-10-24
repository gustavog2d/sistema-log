export type Client = {
  id: string;
  name: string;
  phone: string;
  email: string | null;
  instagram: string | null;
  notes: string | null;
  /** Agendamentos sem os cancelados (AGD-003). */
  appointments_count: number;
  /** Média das avaliações (1 a 5); nulo sem avaliação. */
  average_review: number | null;
  created_at: string;
  updated_at: string;
};

export type ClientPage = { data: Client[]; next_cursor: string | null };
