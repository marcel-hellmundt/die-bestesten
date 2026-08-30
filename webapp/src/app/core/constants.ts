export const ROLE_ORDER: string[] = ['admin', 'maintainer', 'contributor', 'manager'];

export const ROLE_LABEL: Record<string, string> = {
  admin:       'Kernel-Kapitän',
  maintainer:  'Daten-Fee',
  contributor: 'Punkte-Praktikant',
  manager:     'Manager',
};

// Hierarchical: a manager holds at most one explicit role, and it also satisfies every
// requirement ranked at or below it (admin >= maintainer >= contributor >= manager). Mirrors
// _BaseController::ROLE_RANK on the backend.
export const ROLE_RANK: Record<string, number> = {
  manager:     0,
  contributor: 1,
  maintainer:  2,
  admin:       3,
};

export const POSITION_LABEL: Record<string, string> = {
  GOALKEEPER: 'TOR',
  DEFENDER:   'ABW',
  MIDFIELDER: 'MIT',
  FORWARD:    'STU',
};

export const POSITION_COLOR: Record<string, string> = {
  GOALKEEPER: 'var(--position-goalkeeper)',
  DEFENDER:   'var(--position-defender)',
  MIDFIELDER: 'var(--position-midfielder)',
  FORWARD:    'var(--position-forward)',
};
