export type Client = {
  id: string;
  name: string;
  phone: string;
  email: string | null;
  instagram: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
};

export type ClientPage = { data: Client[]; next_cursor: string | null };
