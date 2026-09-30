import { createHashRouter, redirect } from 'react-router';

import Layout from '@/routes/layout/Layout';
import SettingsPage from '@/routes/settings/SettingsPage';
import TemplatePreviewPage from '@/routes/workflows/TemplatePreviewPage';
import WorkflowEditorPage from '@/routes/workflows/WorkflowEditorPage';
import WorkflowListPage from '@/routes/workflows/WorkflowListPage';
import WorkflowOverviewPage from '@/routes/workflows/WorkflowOverviewPage';

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
            // Otherwise `templates` would match `:workflowId` and show "Workflow not found".
            path: 'templates',
            loader: () => redirect('/workflows?templates=1'),
          },
          {
            path: 'templates/:templateId',
            element: <TemplatePreviewPage />,
          },
          {
            path: ':workflowId',
            element: <WorkflowOverviewPage />,
          },
          {
            path: ':workflowId/edit',
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
