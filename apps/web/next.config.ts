import type { NextConfig } from 'next';

const config: NextConfig = {
  transpilePackages: ['@app/api-client', '@app/ui-tokens'],
};

export default config;
