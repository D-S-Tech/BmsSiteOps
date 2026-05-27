/**
 * Re-export the public surface of $lib so consumers can:
 *
 *   import { api, getUser, type Site } from '$lib';
 *
 * Internal modules (types, api, auth) can also be imported by their full path
 * for clarity when a file deals with one concern: e.g.
 *   import { api } from '$lib/api';
 */

export * from './types';
export * from './api';
export * from './auth';
