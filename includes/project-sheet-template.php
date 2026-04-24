<?php
$is_parent_project = !empty($is_parent_project);
$children_projects = is_array($children_projects ?? null) ? $children_projects : [];
$sibling_project_ids = is_array($sibling_project_ids ?? null) ? $sibling_project_ids : [];
$timeline = is_array($timeline ?? null) ? $timeline : null;
$timeline_markers = ($timeline && !empty($timeline['markers'])) ? $timeline['markers'] : [];

$states = ['proyecto', 'en licitación', 'adjudicado', 'en obras', 'finalizado'];
$normalized_state = $state_lower === 'paralizado' ? 'en obras' : $state_lower;
$current_index = array_search($normalized_state, $states, true);
if ($current_index === false) {
    $current_index = 0;
}
$state_window = array_values(array_filter([
    $states[$current_index - 1] ?? null,
    $states[$current_index] ?? null,
    $states[$current_index + 1] ?? null,
]));
?>

<section class="viable-project-sheet state-<?= esc_attr($state) ?>">

    <h2><?= $type_display ? esc_html("$type_display: $name") : esc_html($name) ?></h2>

    <?php if ($short_desc): ?>
        <p class="project-summary"><?= esc_html($short_desc) ?></p>
    <?php endif; ?>

    <?php if ($length): ?>
        <p class="project-length">Longitud: <strong><?= esc_html($length) ?> km</strong></p>
    <?php endif; ?>

    <div class="project-info-box">
        <?php if (!empty($parent_project_id) && !empty($parent_project_name) && !empty($parent_project_url)): ?>
            <div class="info-item info-item-parent">
                <span class="info-label">Parte de</span>
                <span class="info-value"><a href="<?= esc_url($parent_project_url) ?>"><?= esc_html($parent_project_name) ?></a></span>
            </div>
        <?php endif; ?>

        <?php if ($roads_text): ?>
            <div class="info-item">
                <span class="info-label">Rutas</span>
                <span class="info-value"><?= esc_html($roads_text) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($regions_text): ?>
            <div class="info-item">
                <span class="info-label">Regiones</span>
                <span class="info-value"><?= esc_html($regions_text) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$is_parent_project && ($state_lower === 'en licitación' || $state_lower === 'adjudicado') && $duration !== null && $duration !== ''): ?>
            <div class="info-item">
                <span class="info-label">Plazo (meses)</span>
                <span class="info-value"><?= esc_html((string) intval($duration)) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($is_parent_project): ?>
        <div class="children-projects-box">
            <h4>Componentes</h4>
            <?php if (!empty($children_projects)): ?>
                <ul class="children-projects-list">
                    <?php foreach ($children_projects as $child): ?>
                        <li>
                            <a href="<?= esc_url($child['url']) ?>"><?= esc_html($child['title']) ?></a>
                            <?php if (!empty($child['state'])): ?>
                                <span class="child-meta">Estado: <?= esc_html($child['state']) ?></span>
                            <?php endif; ?>
                            <?php if ($child['progress'] !== null && $child['progress'] !== ''): ?>
                                <span class="child-meta">Avance: <?= esc_html((string) intval($child['progress'])) ?>%</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No hay componentes cargados.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($state === 'paralizado' || $state === 'Paralizado'): ?>
            <div class="state-paralizado-alert">
                <strong>Proyecto paralizado</strong>
            </div>
        <?php endif; ?>

        <div class="state-sequence" data-expanded="false">
            <span class="state-label">Estado:</span>
            <div class="state-current-view">
                <span class="state-arrow-left" onclick="this.closest('.state-sequence').dataset.expanded = 'true'; this.parentElement.style.display = 'none'; this.parentElement.nextElementSibling.style.display = 'flex';">&larr;</span>
                <?php foreach ($state_window as $state_item): ?>
                    <span class="state-step<?= $state_item === $normalized_state ? ' current' : (array_search($state_item, $states, true) < $current_index ? ' active' : '') ?>">
                        <?= esc_html(ucfirst($state_item)) ?>
                    </span>
                    <?php if ($state_item !== end($state_window)): ?>
                        <span class="state-arrow">&rarr;</span>
                    <?php endif; ?>
                <?php endforeach; ?>
                <span class="state-arrow-right" onclick="this.closest('.state-sequence').dataset.expanded = 'true'; this.parentElement.style.display = 'none'; this.parentElement.nextElementSibling.style.display = 'flex';">&rarr;</span>
            </div>
            <div class="state-full-view" style="display: none;">
                <?php foreach ($states as $index => $state_item): ?>
                    <span class="state-step<?= $index === $current_index ? ' current' : ($index < $current_index ? ' active' : '') ?>">
                        <?= esc_html(ucfirst($state_item)) ?>
                    </span>
                    <?php if ($index < count($states) - 1): ?>
                        <span class="state-arrow">&rarr;</span>
                    <?php endif; ?>
                <?php endforeach; ?>
                <button class="state-collapse" onclick="this.parentElement.style.display = 'none'; this.parentElement.previousElementSibling.style.display = 'flex';">&times;</button>
            </div>
        </div>

        <?php if ($state_lower === 'en obras' && $timeline): ?>
            <div class="progress-bar-container progress-timeline-container">
                <div class="viable-timeline-rail timeline-bar<?= !empty($timeline['has_full_dates']) ? ' timeline-bar-with-dates' : ' timeline-bar-simple' ?>">
                    <?php if ($timeline['bar_end_pct'] !== null): ?>
                        <div class="viable-timeline-fill" style="width:<?= esc_attr(number_format((float) $timeline['bar_end_pct'], 3, '.', '')) ?>%;">
                            <span class="progress-value-inside"><?= esc_html((string) intval($timeline['progress'])) ?>%</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($timeline['has_full_dates'])): ?>
                        <?php foreach ($timeline_markers as $marker): ?>
                            <?php if (!isset($marker['pct']) || $marker['pct'] === null): continue; endif; ?>
                            <?php $placement = !empty($marker['placement']) ? $marker['placement'] : 'top'; ?>
                            <?php $tier = isset($marker['tier']) ? (int) $marker['tier'] : 0; ?>
                            <div class="timeline-point timeline-point-<?= esc_attr($marker['key']) ?> timeline-point-<?= esc_attr($placement) ?> timeline-tier-<?= esc_attr((string) $tier) ?><?= !empty($marker['current']) ? ' is-current' : '' ?>" style="left:<?= esc_attr(number_format((float) $marker['pct'], 3, '.', '')) ?>%;">
                                <?php if ($placement === 'top'): ?>
                                    <div class="timeline-label-top-date"><?= esc_html($marker['date']) ?></div>
                                    <div class="timeline-label-top-text"><?= esc_html($marker['label']) ?></div>
                                    <span class="timeline-dashed-line"></span>
                                <?php else: ?>
                                    <div class="timeline-label-bottom-date"><?= esc_html($marker['date']) ?></div>
                                    <div class="timeline-label-bottom-text"><?= esc_html($marker['label']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tender_documents): ?>
            <div class="tender-documents-box">
                <h4>Pliegos de licitación</h4>
                <?= wpautop($tender_documents) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($is_parent_project && !empty($map_codes)): ?>
        <?= do_shortcode('[viable_map codes="' . esc_attr(implode(',', $map_codes)) . '" height="300px"]') ?>
    <?php elseif ($code): ?>
        <div class="viable-map-single"
             data-code="<?= esc_attr($code) ?>"
             data-sibling-codes="<?= esc_attr(implode(',', $map_codes)) ?>"
             data-rest-url="<?= esc_url(rest_url('viable/v1/geojson')) ?>"
             style="height: 300px; width: 100%;">
        </div>
    <?php elseif ($map): ?>
        <img src="<?= esc_url($map['url']) ?>" alt="">
    <?php elseif ($image): ?>
        <img src="<?= esc_url($image['url']) ?>" alt="">
    <?php endif; ?>

</section>
