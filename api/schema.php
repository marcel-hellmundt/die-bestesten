<?php
require_once __DIR__ . '/app/routing.php';

$routing = new Routing();
$routes  = $routing->getRoutes();

$methodColors = [
    'GET'    => '#22c55e',
    'POST'   => '#3b82f6',
    'PATCH'  => '#f97316',
    'DELETE' => '#ef4444',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Schema</title>
    <script type="module">
        import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
        mermaid.initialize({
            startOnLoad: true,
            theme: 'base',
            themeVariables: {
                background: '#1e293b',
                primaryColor: '#1e3a5f',
                primaryTextColor: '#e2e8f0',
                primaryBorderColor: '#3b82f6',
                lineColor: '#64748b',
                secondaryColor: '#1e293b',
                tertiaryColor: '#0f172a',
                clusterBkg: '#0f172a',
                clusterBorder: '#334155',
                titleColor: '#94a3b8',
                edgeLabelBackground: '#1e293b',
                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                fontSize: '13px',
            }
        });
    </script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem 1rem;
            line-height: 1.5;
        }

        header {
            max-width: 860px;
            margin: 0 auto 2.5rem;
            border-bottom: 1px solid #1e293b;
            padding-bottom: 1.5rem;
        }

        header h1 { font-size: 1.5rem; font-weight: 600; color: #f8fafc; }
        header p  { color: #94a3b8; font-size: 0.875rem; margin-top: 0.25rem; }

        .resource {
            max-width: 860px;
            margin: 0 auto 1.5rem;
            background: #1e293b;
            border-radius: 8px;
            overflow: hidden;
        }

        .resource-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #334155;
        }

        .resource-header h2 { font-size: 1rem; font-weight: 600; color: #f8fafc; }
        .resource-header p  { font-size: 0.8125rem; color: #94a3b8; margin-top: 0.2rem; }

        .endpoint {
            display: grid;
            grid-template-columns: 72px 1fr;
            gap: 0.75rem;
            align-items: start;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid #0f172a;
        }

        .endpoint:last-child { border-bottom: none; }

        .badge {
            display: inline-block;
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            color: #fff;
            text-align: center;
        }

        .endpoint-info { min-width: 0; }

        .path {
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 0.875rem;
            color: #e2e8f0;
        }

        .description {
            font-size: 0.8125rem;
            color: #94a3b8;
            margin-top: 0.2rem;
        }

        .params {
            margin-top: 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .param {
            font-size: 0.75rem;
            display: flex;
            gap: 0.5rem;
        }

        .param-key {
            font-family: 'SF Mono', 'Fira Code', monospace;
            color: #7dd3fc;
            white-space: nowrap;
        }

        .param-label {
            font-size: 0.6875rem;
            color: #64748b;
            border: 1px solid #334155;
            border-radius: 3px;
            padding: 0 0.3rem;
            align-self: center;
        }

        .param-desc { color: #64748b; }

        footer {
            max-width: 860px;
            margin: 2rem auto 0;
            font-size: 0.75rem;
            color: #334155;
            text-align: right;
        }

        .diagram-section {
            max-width: 860px;
            margin: 0 auto 2.5rem;
        }

        .diagram-section.is-expanded {
            position: fixed;
            inset: 0;
            max-width: none;
            margin: 0;
            z-index: 100;
            background: #0f172a;
            padding: 2rem;
            overflow: auto;
            display: flex;
            flex-direction: column;
        }

        .diagram-section.is-expanded .diagram-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .diagram-section.is-expanded .diagram-wrap .mermaid {
            flex: 1;
            align-items: center;
        }

        .diagram-section.is-expanded .diagram-wrap .mermaid svg {
            width: 100% !important;
            height: 100% !important;
            max-width: none !important;
        }

        .diagram-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.25rem;
        }

        .diagram-section h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #f8fafc;
            margin-bottom: 0;
        }

        .btn-expand {
            font-size: 0.75rem;
            color: #64748b;
            background: none;
            border: 1px solid #334155;
            border-radius: 4px;
            padding: 0.2rem 0.6rem;
            cursor: pointer;
            white-space: nowrap;
            transition: color 0.15s, border-color 0.15s;
            flex-shrink: 0;
        }

        .btn-expand:hover {
            color: #e2e8f0;
            border-color: #64748b;
        }

        .diagram-section p {
            font-size: 0.8125rem;
            color: #94a3b8;
            margin-bottom: 1rem;
        }

        .diagram-wrap {
            background: #1e293b;
            border-radius: 8px;
            padding: 1.5rem;
            overflow-x: auto;
        }

        .diagram-wrap .mermaid {
            display: flex;
            justify-content: center;
        }
    </style>
</head>
<body>

<header>
    <h1>API Schema</h1>
    <p><?= htmlspecialchars('https://api.claude.die-bestesten.de') ?></p>
</header>

<div class="diagram-section" id="diagram-section">
    <div class="diagram-header">
        <h2>Datenbankschema</h2>
        <button class="btn-expand" onclick="toggleDiagram()">⤢ Erweitern</button>
    </div>
    <p>Pfeile zeigen Fremdschlüssel-Abhängigkeiten. Die Stufen geben die Migrations-Reihenfolge vor — eine Tabelle darf erst befüllt werden, wenn alle Tabellen der vorherigen Stufen vorhanden sind.</p>
    <div class="diagram-wrap">
        <pre class="mermaid">
flowchart TD
    subgraph S1["① Basis — keine Abhängigkeiten"]
        direction LR
        country("country")
        season("season")
        league("league")
    end

    subgraph S2["② Abhängig von Basis"]
        direction LR
        club("club")
        division("division")
        player("player")
        matchday("matchday")
    end

    subgraph S3["③ Verknüpfungstabellen"]
        direction LR
        player_in_season("player_in_season")
        player_in_club("player_in_club")
        club_in_season("club_in_season")
    end

    subgraph S4["④ Bewertungen &amp; Fenster"]
        direction LR
        player_rating("player_rating")
        transferwindow("transferwindow")
    end

    country -->|country_id| club
    country -->|country_id| division
    country -.->|country_id ?| player

    season -->|season_id| matchday
    season -->|season_id| player_in_season
    season -->|season_id| club_in_season

    player -->|player_id| player_in_season
    player -->|player_id| player_in_club
    player -->|player_id| player_rating

    club -->|club_id| player_in_club
    club -->|club_id| club_in_season

    division -->|division_id| club_in_season

    matchday -->|matchday_id| player_rating
    matchday -->|matchday_id| transferwindow
        </pre>
    </div>
</div>

<?php foreach ($routes as $route): ?>
    <?php $docs = $route->docs; if (empty($docs)) continue; ?>
    <section class="resource">
        <div class="resource-header">
            <h2><?= htmlspecialchars($docs['title']) ?></h2>
            <?php if (!empty($docs['description'])): ?>
                <p><?= htmlspecialchars($docs['description']) ?></p>
            <?php endif; ?>
        </div>

        <?php foreach ($docs['endpoints'] as $ep): ?>
            <div class="endpoint">
                <div>
                    <span class="badge" style="background:<?= $methodColors[$ep['method']] ?? '#64748b' ?>">
                        <?= htmlspecialchars($ep['method']) ?>
                    </span>
                </div>
                <div class="endpoint-info">
                    <div class="path"><?= htmlspecialchars($ep['path']) ?></div>
                    <?php if (!empty($ep['description'])): ?>
                        <div class="description"><?= htmlspecialchars($ep['description']) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($ep['path_params']) || !empty($ep['query_params'])): ?>
                        <div class="params">
                            <?php foreach ($ep['path_params'] ?? [] as $key => $desc): ?>
                                <div class="param">
                                    <span class="param-key"><?= htmlspecialchars($key) ?></span>
                                    <span class="param-label">path</span>
                                    <span class="param-desc"><?= htmlspecialchars($desc) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php foreach ($ep['query_params'] ?? [] as $key => $desc): ?>
                                <div class="param">
                                    <span class="param-key"><?= htmlspecialchars($key) ?></span>
                                    <span class="param-label">query</span>
                                    <span class="param-desc"><?= htmlspecialchars($desc) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>

<footer>Generiert aus routing.php &mdash; <?= date('d.m.Y H:i') ?></footer>

<script>
    function toggleDiagram() {
        const section = document.getElementById('diagram-section');
        const btn     = section.querySelector('.btn-expand');
        const expanded = section.classList.toggle('is-expanded');
        btn.textContent = expanded ? '⤡ Verkleinern' : '⤢ Erweitern';
        document.body.style.overflow = expanded ? 'hidden' : '';
    }
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            const section = document.getElementById('diagram-section');
            if (section.classList.contains('is-expanded')) toggleDiagram();
        }
    });
</script>
</body>
</html>
