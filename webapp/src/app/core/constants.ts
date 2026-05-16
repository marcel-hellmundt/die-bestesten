export const ROLE_ORDER: string[] = ['admin', 'maintainer', 'manager'];

export const ROLE_LABEL: Record<string, string> = {
  admin:      'Kernel-Kapitän',
  maintainer: 'Daten-Fee',
  manager:    'Manager',
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
