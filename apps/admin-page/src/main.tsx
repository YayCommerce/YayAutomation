import './main.css';

import React from 'react';
import { createRoot } from 'react-dom/client';

import App from './App';

// Light only: the kit's `.yay-dark` styles stay unused.
document.documentElement.classList.add('yay-light');

const rootElement = document.getElementById('yaymatik');
if (rootElement) {
  createRoot(rootElement).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
  );
}
