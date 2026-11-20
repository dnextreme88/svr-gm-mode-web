import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(() => {
    cleanup();
    // Page state kept in the query string (`useUrlParam`) mustn't leak
    // between tests.
    window.history.replaceState(null, '', '/');
});
