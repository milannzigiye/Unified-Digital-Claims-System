<?php
require_once __DIR__ . '/components/head.php';

// Force browser to fetch fresh landing markup/styles after design rollbacks.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Bank of Kigali | UDCS Landing'); ?>
    <style>
        :root {
            --landing-blue-900: #023b7d;
            --landing-blue-800: #034893;
            --landing-blue-700: #034ea2;
            --landing-blue-600: #1f6fc8;
            --landing-card: rgba(10, 91, 182, 0.35);
            --landing-border: rgba(215, 232, 255, 0.34);
            --landing-text-soft: rgba(234, 245, 255, 0.88);
        }

        body {
            background:
                radial-gradient(circle at 86% 8%, rgba(31, 111, 200, 0.42), transparent 34%),
                radial-gradient(circle at 14% 88%, rgba(3, 78, 162, 0.36), transparent 38%),
                linear-gradient(145deg, var(--landing-blue-900) 0%, var(--landing-blue-800) 52%, var(--landing-blue-700) 100%);
            color: #ffffff;
            min-height: 100vh;
            font-family: 'Sora', 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            display: flex;
            flex-direction: column;
        }

        .landing-wrap {
            width: 100%;
            max-width: 1320px;
            margin: 0 auto;
            padding: 0 1rem;
            flex: 1 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero {
            width: 100%;
            border: 1px solid var(--landing-border);
            border-radius: 1.25rem;
            background: linear-gradient(145deg, rgba(12, 88, 173, 0.42), rgba(255, 255, 255, 0.14));
            box-shadow: 0 24px 46px rgba(0, 11, 29, 0.34);
            padding: clamp(1rem, 2.4vw, 2rem);
        }

        .hero-subtitle {
            margin-top: 0.1rem;
            max-width: 110ch;
            line-height: 1.5;
            font-size: clamp(0.95rem, 1.2vw, 1.08rem);
            color: var(--landing-text-soft);
        }

        .metrics {
            margin-top: 0.95rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.7rem;
        }

        .metric-card {
            border: 1px solid var(--landing-border);
            border-radius: 0.95rem;
            background: var(--landing-card);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12), 0 8px 18px rgba(0, 18, 49, 0.26);
            padding: 0.75rem;
            min-height: 6.2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .metric-label {
            font-size: 0.7rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(225, 242, 255, 0.82);
            font-weight: 700;
        }

        .metric-value {
            margin-top: 0.36rem;
            font-size: clamp(1.3rem, 2.2vw, 2rem);
            line-height: 1;
            font-weight: 800;
            color: #ffffff;
        }

        .metric-note {
            margin-top: 0.3rem;
            font-size: 0.78rem;
            color: rgba(224, 241, 255, 0.84);
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        @media (max-width: 1024px) {
            .metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .metrics {
                grid-template-columns: 1fr;
            }

            .landing-wrap {
                align-items: flex-start;
                padding-top: 0.5rem;
                padding-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <header class="ui-navbar sticky top-0 z-[1200] w-full border-b border-bk-border bg-bk-surface/95">
        <div class="flex min-h-[5rem] w-full flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <a href="index.php" class="flex items-center gap-3">
                <img src="Images/logo.png" alt="Bank of Kigali" class="h-10 w-10 rounded-md bg-white p-1 shadow-soft">
                <div class="flex flex-col leading-tight">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em] text-bk-muted">Bank of Kigali</span>
                        <span class="h-3 w-px bg-bk-border/90" aria-hidden="true"></span>
                        <span class="font-display text-xl font-bold tracking-tight text-bk-text">UDCS</span>
                    </div>
                    <span class="hidden text-xs font-semibold uppercase tracking-[0.12em] text-bk-muted sm:block">Unified Digital Claims System</span>
                </div>
            </a>

            <div class="flex items-center gap-2 sm:gap-3">
                <a href="login.php" class="ui-btn ui-btn-sm ui-btn-ghost !border-bk-border !text-bk-text">Staff Access</a>
                <a href="claimant-access.php?mode=signup" class="ui-btn ui-btn-sm ui-btn-primary">Claimant Access</a>
            </div>
        </div>
    </header>

    <main class="landing-wrap">
        <section id="overview" class="hero">
            <p class="hero-subtitle">
                UDCS gives families and authorized representatives one clear digital path to claim deceased Bank of Kigali customer assets, from guided submission to legal review, finance settlement, and live status tracking.
            </p>

            <p class="mt-3 text-xs uppercase tracking-[0.1em] text-white/75">Projected Community Impact Indicators</p>
            <div id="community-impact" class="metrics" aria-label="Community impact highlights with animated counters">
                <article class="metric-card">
                    <p class="metric-label">Families Supported</p>
                    <p class="metric-value" data-count="1000" data-suffix="+">0</p>
                    <p class="metric-note">Projected households that can be served on a scaled rollout.</p>
                </article>
                <article class="metric-card">
                    <p class="metric-label">Target Turnaround</p>
                    <p class="metric-value" data-count="7" data-suffix=" days">0</p>
                    <p class="metric-note">Target claim movement from submission to final review outcome.</p>
                </article>
                <article class="metric-card">
                    <p class="metric-label">Guided Stages</p>
                    <p class="metric-value" data-count="5" data-suffix=" stages">0</p>
                    <p class="metric-note">Deceased identity, family path, people, BK assets, and evidence.</p>
                </article>
                <article class="metric-card">
                    <p class="metric-label">Branch Visits</p>
                    <p class="metric-value" data-count="1" data-suffix=" visit">0</p>
                    <p class="metric-note">Designed to reduce repeated physical follow-ups after submission.</p>
                </article>
                <article class="metric-card">
                    <p class="metric-label">Review Teams</p>
                    <p class="metric-value" data-count="2" data-suffix=" teams">0</p>
                    <p class="metric-note">Legal and Finance review the claim through one traceable workflow.</p>
                </article>
                <article class="metric-card">
                    <p class="metric-label">Status Access</p>
                    <p class="metric-value" data-count="24" data-suffix="/7">0</p>
                    <p class="metric-note">Claimants can check progress without waiting for manual updates.</p>
                </article>
                <article class="metric-card">
                    <p class="metric-label">Rework Reduction</p>
                    <p class="metric-value" data-count="45" data-suffix="%">0</p>
                    <p class="metric-note">Lower resubmissions from clearer requirements and OCR checks.</p>
                </article>
                <article class="metric-card">
                    <p class="metric-label">Escalation Reduction</p>
                    <p class="metric-value" data-count="35" data-suffix="%">0</p>
                    <p class="metric-note">Expected decrease in avoidable complaint escalations.</p>
                </article>
            </div>
        </section>

    </main>

    <footer class="ui-navbar w-full border-y border-bk-border bg-bk-surface/95" aria-label="Landing footer">
        <div class="mx-auto flex w-full max-w-[1320px] items-center justify-center px-4 py-3 sm:px-6 lg:px-8">
            <p class="w-full text-center text-xs text-bk-muted">&copy; 2026 Bank of Kigali. All rights reserved.</p>
        </div>
    </footer>

    <script>
        (() => {
            const counters = document.querySelectorAll('.metric-value[data-count]');
            if (!counters.length) return;

            const easeInOutCubic = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

            const runCounter = (el) => {
                if (el.dataset.done === '1') return;
                el.dataset.done = '1';

                const target = Number(el.dataset.count || 0);
                const suffix = el.dataset.suffix || '';
                const prefix = el.dataset.prefix || '';
                const duration = 7000;
                const startTime = performance.now();

                const tick = (now) => {
                    const elapsed = now - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    const value = Math.round(target * easeInOutCubic(progress));
                    el.textContent = `${prefix}${value.toLocaleString()}${suffix}`;

                    if (progress < 1) {
                        requestAnimationFrame(tick);
                    }
                };

                requestAnimationFrame(tick);
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        runCounter(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.35 });

            counters.forEach((el) => observer.observe(el));
        })();
    </script>
</body>
</html>
