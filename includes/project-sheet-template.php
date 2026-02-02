<section class="viable-project-sheet state-<?= esc_attr($state) ?>">

    <h2><?= $type_display ? esc_html("$type_display – $name") : esc_html($name) ?></h2>

    <?php if ($short_desc): ?>
        <p class="project-summary"><?= esc_html($short_desc) ?></p>
    <?php endif; ?>
    
    <?php if ($length): ?>
        <p class="project-length">Longitud: <strong><?= esc_html($length) ?> km</strong></p>
    <?php endif; ?>

    <div class="project-info-box">
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
        
        <?php 
        if (($state_lower === 'finalizado' && $end_date) || ($state_lower === 'en obras' && $end_date)): 
        ?>
            <div class="info-item">
                <span class="info-label"><?= $state_lower === 'finalizado' ? 'Finalización' : 'Finalización prevista' ?></span>
                <span class="info-value"><?= esc_html($end_date) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if (($state_lower === 'en licitación' || $state_lower === 'adjudicado') && $duration): ?>
            <div class="info-item">
                <span class="info-label">Plazo</span>
                <span class="info-value"><?= esc_html($duration) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($state === 'paralizado' || $state === 'Paralizado'): ?>
        <div class="state-paralizado-alert">
            <span class="alert-icon">⚠️</span>
            <strong>Proyecto Paralizado</strong>
        </div>
    <?php else: ?>
        <div class="state-sequence" data-expanded="false">
            <span class="state-label">Estado:</span>
            <div class="state-current-view">
                <span class="state-arrow-left" onclick="document.querySelector('.state-sequence').dataset.expanded = 'true'; this.parentElement.style.display = 'none'; this.parentElement.nextElementSibling.style.display = 'flex';">←</span>
                <span class="state-step current"><?= esc_html(ucfirst($state_lower)) ?></span>
                <span class="state-arrow-right" onclick="document.querySelector('.state-sequence').dataset.expanded = 'true'; this.parentElement.style.display = 'none'; this.parentElement.nextElementSibling.style.display = 'flex';">→</span>
            </div>
            <div class="state-full-view" style="display: none;">
                <?php
                $states = ['proyecto', 'en licitación', 'adjudicado', 'en obras', 'finalizado'];
                $current_index = array_search($state_lower, $states);
                
                foreach ($states as $index => $s) {
                    $class = 'state-step';
                    if ($index <= $current_index) {
                        $class .= ' active';
                    }
                    if ($index === $current_index) {
                        $class .= ' current';
                    }
                    echo '<span class="' . $class . '">' . esc_html(ucfirst($s)) . '</span>';
                    if ($index < count($states) - 1) {
                        echo '<span class="state-arrow">→</span>';
                    }
                }
                ?>
                <button class="state-collapse" onclick="this.parentElement.style.display = 'none'; this.parentElement.previousElementSibling.style.display = 'flex';">×</button>
            </div>
        </div>
    <?php endif; ?>
    
    <?php
    // Determinar la fecha "desde" fuera del div de estado
    $desde_date = null;
    $desde_label = '';
    if ($state_lower === 'en licitación' && isset($bid_date_formatted)) {
        $desde_date = $bid_date_formatted;
        $desde_label = 'Desde';
    } elseif ($state_lower === 'adjudicado' && isset($award_date_formatted)) {
        $desde_date = $award_date_formatted;
        $desde_label = 'Desde';
    } elseif ($state_lower === 'en obras' && isset($start_date_formatted)) {
        $desde_date = $start_date_formatted;
        $desde_label = 'Desde';
    }
    ?>
    <?php if ($desde_date): ?>
        <div class="state-since"><?= esc_html($desde_label) ?>: <?= esc_html($desde_date) ?></div>
    <?php endif; ?>

    <?php if ($state === 'En obras' && $progress !== null): ?>
        <div class="progress-bar-container">
            <div class="progress-bar-label">
                <?= intval($progress) ?>%<?php if ($progress_updated_on): ?> (<?= esc_html($progress_updated_on) ?>)<?php endif; ?>
            </div>
            <div class="progress-bar">
                <div class="progress-bar-fill" style="width:<?= intval($progress) ?>%"></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tender_documents): ?>
        <div class="tender-documents-box">
            <h4>Pliegos de licitación</h4>
            <?= wpautop($tender_documents) ?>
        </div>
    <?php endif; ?>

    <?php if ($code): ?>
        <div id="viable-map" 
             data-code="<?= esc_attr($code) ?>" 
             style="height: 300px; width: 100%;">
        </div>
    <?php elseif ($map): ?>
        <img src="<?= esc_url($map['url']) ?>" alt="">
    <?php elseif ($image): ?>
        <img src="<?= esc_url($image['url']) ?>" alt="">
    <?php endif; ?>

</section>
