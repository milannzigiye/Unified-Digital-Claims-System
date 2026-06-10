<?php
// Tags: [COMPONENT] [UI] [CLAIMS]
require_once __DIR__ . '/helpers.php';

if (!function_exists('udcs_claims_list_expand_icon')) {
    function udcs_claims_list_expand_icon(): string
    {
        return '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5.5 8 4.5 4.5L14.5 8"/></svg>';
    }
}

if (!function_exists('udcs_claims_list_render_expand_button')) {
    function udcs_claims_list_render_expand_button(string $panelId, array $options = []): void
    {
        $label = trim((string) ($options['label'] ?? 'Show claim details'));
        if ($label === '') {
            $label = 'Show claim details';
        }

        $class = trim((string) ($options['class'] ?? ''));
        $attrs = [
            'type' => 'button',
            'class' => bk_class('udcs-expand-toggle', $class),
            'data-udcs-expand-toggle' => 'true',
            'data-udcs-target' => $panelId,
            'aria-expanded' => 'false',
            'aria-controls' => $panelId,
            'aria-label' => $label,
        ];

        if (!empty($options['title'])) {
            $attrs['title'] = (string) $options['title'];
        } else {
            $attrs['title'] = $label;
        }

        echo '<button ' . bk_attrs($attrs) . '>';
        echo '<span class="udcs-expand-toggle-icon">' . udcs_claims_list_expand_icon() . '</span>';
        echo '</button>';
    }
}

if (!function_exists('udcs_claims_list_render_expand_row')) {
    function udcs_claims_list_render_expand_row(string $panelId, int $colspan, array $sections, array $options = []): void
    {
        $rowClass = trim((string) ($options['row_class'] ?? ''));
        $panelClass = trim((string) ($options['panel_class'] ?? ''));
        $gridClass = trim((string) ($options['grid_class'] ?? ''));

        echo '<tr id="' . bk_e($panelId) . '" class="' . bk_e(bk_class('udcs-expand-row', $rowClass)) . '" data-udcs-expand-panel-row hidden>';
        echo '<td colspan="' . max(1, $colspan) . '">';
        echo '<div class="' . bk_e(bk_class('udcs-expand-panel', $panelClass)) . '">';
        echo '<div class="' . bk_e(bk_class('udcs-expand-grid', $gridClass)) . '">';

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = trim((string) ($section['title'] ?? ''));
            $html = '';

            if (isset($section['html']) && trim((string) $section['html']) !== '') {
                $html = (string) $section['html'];
            } elseif (!empty($section['lines']) && is_array($section['lines'])) {
                $html = udcs_claims_list_lines_html((array) $section['lines']);
            } elseif (isset($section['copy'])) {
                $html = '<div class="udcs-expand-copy">' . bk_e((string) $section['copy']) . '</div>';
            }

            if ($title === '' && trim($html) === '') {
                continue;
            }

            echo '<section class="udcs-expand-block">';
            if ($title !== '') {
                echo '<p class="udcs-expand-kicker">' . bk_e($title) . '</p>';
            }
            echo $html;
            echo '</section>';
        }

        echo '</div>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
}

if (!function_exists('udcs_claims_list_lines_html')) {
    function udcs_claims_list_lines_html(array $lines): string
    {
        $html = '<div class="udcs-expand-lines">';
        foreach ($lines as $line) {
            if (is_array($line)) {
                $label = trim((string) ($line['label'] ?? ''));
                $value = trim((string) ($line['value'] ?? ''));
            } else {
                $label = '';
                $value = trim((string) $line);
            }

            if ($label === '' && $value === '') {
                continue;
            }

            $html .= '<div class="udcs-expand-line">';
            if ($label !== '') {
                $html .= '<strong>' . bk_e($label) . ':</strong> ';
            }
            $html .= '<span>' . bk_e($value !== '' ? $value : 'Not recorded') . '</span>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('udcs_claims_list_render_assets')) {
    function udcs_claims_list_render_assets(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        ?>
        <script>
        (() => {
            if (window.__udcsClaimsListExpandBound) {
                return;
            }
            window.__udcsClaimsListExpandBound = true;

            const closeGroup = (group, exceptId = '') => {
                group.querySelectorAll('[data-udcs-expand-toggle]').forEach((btn) => {
                    const targetId = btn.getAttribute('data-udcs-target') || '';
                    const panel = targetId ? document.getElementById(targetId) : null;
                    if (!panel || targetId === exceptId) {
                        return;
                    }
                    btn.setAttribute('aria-expanded', 'false');
                    btn.classList.remove('is-open');
                    panel.hidden = true;
                    panel.classList.remove('is-open');
                });
            };

            document.addEventListener('click', (event) => {
                const btn = event.target.closest('[data-udcs-expand-toggle]');
                if (!btn) {
                    return;
                }

                const targetId = btn.getAttribute('data-udcs-target') || '';
                const panel = targetId ? document.getElementById(targetId) : null;
                if (!panel) {
                    return;
                }

                const group = btn.closest('[data-udcs-expand-group]') || document;
                const singleMode = group === document || group.getAttribute('data-udcs-expand-single') !== 'false';
                const isOpen = btn.getAttribute('aria-expanded') === 'true';

                if (singleMode) {
                    closeGroup(group, isOpen ? '' : targetId);
                }

                if (isOpen) {
                    btn.setAttribute('aria-expanded', 'false');
                    btn.classList.remove('is-open');
                    panel.hidden = true;
                    panel.classList.remove('is-open');
                    return;
                }

                btn.setAttribute('aria-expanded', 'true');
                btn.classList.add('is-open');
                panel.hidden = false;
                panel.classList.add('is-open');
            });
        })();
        </script>
        <?php
    }
}
