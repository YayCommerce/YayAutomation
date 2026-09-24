import { createHashRouter, redirect } from 'react-router';

import Layout from '@/routes/layout/Layout';
import SettingsPage from '@/routes/settings/SettingsPage';
import WorkflowEditorPage from '@/routes/workflows/WorkflowEditorPage';
import WorkflowListPage from '@/routes/workflows/WorkflowListPage';

export const router = createHashRouter([
  {
    path: '/',
    element: <Layout />,
    children: [
      {
        index: true,
        loader: () => redirect('/workflows'),
      },
      {
        path: 'workflows',
        children: [
          {
            index: true,
            element: <WorkflowListPage />,
          },
          {
            path: ':workflowId',
            element: <WorkflowEditorPage />,
          },
        ],
      },
      {
        path: 'settings',
        element: <SettingsPage />,
      },
    ],
  },
]);
