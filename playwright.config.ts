import 'dotenv/config';
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/e2e',
  // Several specs share the KAYZART_POST_ID fixture post and the same admin
  // settings, so running them in parallel makes them collide over navigation,
  // saves, and deletions. Keep the suite serial until those specs own their
  // own data.
  workers: 1,
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  use: {
    baseURL: process.env.WP_BASE_URL || 'http://localhost',
    headless: true,
    trace: 'on-first-retry',
  },
});
