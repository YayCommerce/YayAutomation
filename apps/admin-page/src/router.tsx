import { createHashRouter, redirect } from 'react-router';

import Layout from '@/routes/layout/Layout';
import SettingsPage from '@/routes/settings/SettingsPage';
import WorkflowEditorPage from '@/routes/workflows/pages/editor/WorkflowEditorPage';
import WorkflowListPage from '@/routes/workflows/pages/list/WorkflowListPage';
import WorkflowOverviewPage from '@/routes/workflows/pages/overview/WorkflowOverviewPage';
import TemplatePreviewPage from '@/routes/workflows/pages/template-preview/TemplatePreviewPage';

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
            loader: () => redirect('/workflows?templates=list'),
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
