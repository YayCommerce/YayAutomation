import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { visualizer } from 'rollup-plugin-visualizer';
import { defineConfig, loadEnv } from 'vite';
import pluginExternal, { Options } from 'vite-plugin-external';

process.env = { ...process.env, ...loadEnv(process.env.mode || 'development', process.cwd()) };

const port = Number(process.env.VITE_PORT) || 3020;

// Which edition `@edition` loads: release.sh builds the free zip with `lite`.
const edition = process.env.YAYMATIK_EDITION === 'lite' ? 'lite' : 'pro';

const terserOptions = {
  output: {
    comments: /translators:/i,
  },
  compress: {
    passes: 2,
  },
  mangle: {
    reserved: ['__', '_n', '_nx', '_x'],
  },
};

const externalOptions: Options = {
  /** @type 'auto' */
  interop: 'auto',

  development: {
    externals: {
      '@wordpress/hooks': 'wp.hooks',
      '@wordpress/i18n': 'wp.i18n',
      '@wordpress/date': 'wp.date',
    },
  },

  production: {
    // Own stand-in folder: dev rewrites node_modules/.vite_external while a build may be reading it.
    cacheDir: 'node_modules/.vite_external_build',
    externals: {
      '@wordpress/hooks': 'wp.hooks',
      '@wordpress/i18n': 'wp.i18n',
      '@wordpress/date': 'wp.date',
      react: 'React',
      'react-dom': 'ReactDOM',
      'react-dom/client': 'ReactDOM',
      // motion's prebuilt code imports the automatic runtime; WordPress ships it as `react-jsx-runtime` (6.6+).
      'react/jsx-runtime': 'ReactJSXRuntime',
    },
  },
};

// https://vitejs.dev/config/
export default defineConfig({
  root: './src',

  plugins: [
    react({ jsxRuntime: 'classic' }),
    tailwindcss(),
    pluginExternal(externalOptions),
    process.env.ANALYZE === 'true' && visualizer({ template: 'raw-data', open: true, filename: 'stats.html' }),
  ],

  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@edition': path.resolve(__dirname, `./src/${edition}`),
    },
  },
  build: {
    minify: 'terser',
    terserOptions: terserOptions,
    manifest: false,
    emptyOutDir: true,
    outDir: path.resolve('../../assets', 'dist/admin-page'),
    assetsDir: '',
    cssCodeSplit: false,
    rollupOptions: {
      input: {
        main: path.resolve(__dirname, 'src/main.tsx'),
      },
      output: {
        entryFileNames: '[name].js',
        assetFileNames: '[name].[ext]',
        chunkFileNames: '[name]-[hash].js',
        manualChunks(id) {
          if (
            id.includes('/motion/') ||
            id.includes('motion-dom') ||
            id.includes('framer-motion') ||
            id.includes('/motion-ui/')
          ) {
            return 'motion';
          }
        },
      },
    },
  },
  server: {
    cors: true,
    strictPort: true,
    port,
    origin: process.env.VITE_SERVER_ORIGIN || `http://localhost:${port}`,
    hmr: {
      port,
      host: 'localhost',
      protocol: 'ws',
    },
  },
});
