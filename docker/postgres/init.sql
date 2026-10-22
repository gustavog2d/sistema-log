-- Executado pelo PostgreSQL ao criar o volume (como o papel dono, POSTGRES_USER).
--
-- Papéis (FND-005, ADR 0002):
--   app          dono das tabelas; roda migrations e ignora o RLS por ser superusuário no ambiente local.
--   app_runtime  papel da API; sem superusuário e sem BYPASSRLS, portanto sujeito às políticas RLS.
--                Só lê as tabelas de plataforma (tenants, assinaturas, pagamentos).
--   app_platform papel das operações de plataforma (cadastro, cobrança); escreve nas tabelas de plataforma,
--                também sem superusuário e sem BYPASSRLS (para escrever em tabela de assinante precisa
--                informar o tenant, como qualquer outro).
-- A senha abaixo é só de desenvolvimento; homologação e produção usam segredos.
CREATE ROLE app_runtime LOGIN PASSWORD 'app_runtime' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE app_platform LOGIN PASSWORD 'app_platform' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

-- Banco usado pela suíte de testes (PostgreSQL real, por causa de RLS e constraints de exclusão).
CREATE DATABASE app_test;

\connect app
GRANT USAGE ON SCHEMA public TO app_runtime;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_runtime;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_runtime;
GRANT USAGE ON SCHEMA public TO app_platform;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_platform;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_platform;

\connect app_test
GRANT USAGE ON SCHEMA public TO app_runtime;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_runtime;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_runtime;
GRANT USAGE ON SCHEMA public TO app_platform;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_platform;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_platform;
