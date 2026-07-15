<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Admin_Calendar {

    public static function enqueue_assets() {
        wp_enqueue_script(
            'cjs-fullcalendar',
            CJS_PLUGIN_URL . 'assets/vendor/fullcalendar/index.global.min.js',
            [],
            '6.1.19',
            true
        );

        wp_enqueue_script(
            'cjs-fullcalendar-lt',
            CJS_PLUGIN_URL . 'assets/vendor/fullcalendar/lt.global.min.js',
            ['cjs-fullcalendar'],
            '6.1.19',
            true
        );

        wp_enqueue_style(
            'cjs-calendar',
            CJS_PLUGIN_URL . 'assets/css/calendar.css',
            ['cjs-admin'],
            filemtime(CJS_PLUGIN_DIR . 'assets/css/calendar.css')
        );

        wp_enqueue_script(
            'cjs-calendar',
            CJS_PLUGIN_URL . 'assets/js/calendar.js',
            ['jquery', 'cjs-admin', 'cjs-fullcalendar-lt'],
            filemtime(CJS_PLUGIN_DIR . 'assets/js/calendar.js'),
            true
        );

        wp_localize_script('cjs-calendar', 'cjs_calendar_data', [
            'settings' => CJS_Scheduler::get_settings(1),
            'types' => get_option('cjs_interval_types', []),
            'orders_url' => admin_url('admin.php?page=cjs-orders-list'),
            'weekdays' => [
                'mon' => __('Pirmadienis', 'custom-jewelry-system'),
                'tue' => __('Antradienis', 'custom-jewelry-system'),
                'wed' => __('Trečiadienis', 'custom-jewelry-system'),
                'thu' => __('Ketvirtadienis', 'custom-jewelry-system'),
                'fri' => __('Penktadienis', 'custom-jewelry-system'),
                'sat' => __('Šeštadienis', 'custom-jewelry-system'),
                'sun' => __('Sekmadienis', 'custom-jewelry-system')
            ]
        ]);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $types = get_option('cjs_interval_types', []);
        if (!is_array($types)) {
            $types = [];
        }
        ?>
        <div class="wrap cjs-calendar-wrap">
            <h1><?php _e('Gamybos kalendorius', 'custom-jewelry-system'); ?></h1>

            <div class="cjs-calendar-toolbar">
                <button type="button" class="button button-primary cjs-cal-recalc-btn" id="cjs-cal-recalc">
                    <svg class="cjs-cal-sparkle" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30" width="16" height="16" aria-hidden="true">
                        <path fill="#ffffff" d="M14.217 19.707l-1.112 2.547c-.427.979-1.782.979-2.21 0l-1.112-2.547c-.99-2.267-2.771-4.071-4.993-5.057L1.73 13.292c-.973-.432-.973-1.848 0-2.28l2.965-1.316C6.974 8.684 8.787 6.813 9.76 4.47l1.126-2.714c.418-1.007 1.81-1.007 2.228 0L14.24 4.47c.973 2.344 2.786 4.215 5.065 5.226l2.965 1.316c.973.432.973 1.848 0 2.28l-3.061 1.359C16.988 15.637 15.206 17.441 14.217 19.707zM24.481 27.796l-.339.777c-.248.569-1.036.569-1.284 0l-.339-.777c-.604-1.385-1.693-2.488-3.051-3.092l-1.044-.464c-.565-.251-.565-1.072 0-1.323l.986-.438c1.393-.619 2.501-1.763 3.095-3.195l.348-.84c.243-.585 1.052-.585 1.294 0l.348.84c.594 1.432 1.702 2.576 3.095 3.195l.986.438c.565.251.565 1.072 0 1.323l-1.044.464C26.174 25.308 25.085 26.411 24.481 27.796z"/>
                    </svg>
                    <span class="cjs-cal-recalc-label"><?php _e('Perskaičiuoti grafiką', 'custom-jewelry-system'); ?></span>
                </button>
                <label class="cjs-cal-treat-label">
                    <input type="checkbox" id="cjs-cal-treat-done" />
                    <?php _e('Praėjusį darbą laikyti atliktu', 'custom-jewelry-system'); ?>
                </label>
                <button type="button" class="button" id="cjs-cal-new"><?php _e('+ Naujas įrašas', 'custom-jewelry-system'); ?></button>
                <button type="button" class="button" id="cjs-cal-settings"><span class="dashicons dashicons-admin-generic"></span> <?php _e('Nustatymai', 'custom-jewelry-system'); ?></button>
                <span class="cjs-cal-legend">
                    <span class="cjs-legend-item"><?php _e('Trumpas', 'custom-jewelry-system'); ?><span class="cjs-legend-gradient"></span><?php _e('Ilgas darbas', 'custom-jewelry-system'); ?></span>
                    <?php foreach ($types as $key => $type) :
                        if ($key === 'work' || !is_array($type)) {
                            continue;
                        }
                        ?>
                        <span class="cjs-legend-item"><span class="cjs-legend-dot" style="background:<?php echo esc_attr($type['color'] ?? '#616161'); ?>;"></span><?php echo esc_html($type['label'] ?? $key); ?></span>
                    <?php endforeach; ?>
                </span>
            </div>

            <div id="cjs-calendar-notice"></div>

            <div class="cjs-calendar-holder" id="cjs-calendar-holder">
                <div id="cjs-calendar"></div>
            </div>

            <div id="cjs-interval-modal" class="cjs-modal">
                <div class="cjs-modal-content cjs-interval-modal-content">
                    <span class="cjs-modal-close">&times;</span>
                    <h2 id="cjs-ci-heading"><?php _e('Įrašas', 'custom-jewelry-system'); ?></h2>
                    <input type="hidden" id="cjs-ci-id" value="0" />

                    <div class="cjs-form-row">
                        <label for="cjs-ci-name"><?php _e('Pavadinimas', 'custom-jewelry-system'); ?></label>
                        <input type="text" id="cjs-ci-name" />
                    </div>

                    <div class="cjs-form-row" id="cjs-ci-type-row">
                        <label for="cjs-ci-type"><?php _e('Tipas', 'custom-jewelry-system'); ?></label>
                        <select id="cjs-ci-type">
                            <?php foreach ($types as $key => $type) :
                                if ($key === 'work' || !is_array($type)) {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($type['label'] ?? $key); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cjs-form-row cjs-ci-times">
                        <div>
                            <label for="cjs-ci-start-date"><?php _e('Pradžia', 'custom-jewelry-system'); ?></label>
                            <div class="cjs-datetime-pair">
                                <input type="date" id="cjs-ci-start-date" />
                                <input type="text" inputmode="numeric" class="cjs-time-text" id="cjs-ci-start-time" placeholder="HH:MM" />
                            </div>
                        </div>
                        <div>
                            <label for="cjs-ci-end-date"><?php _e('Pabaiga', 'custom-jewelry-system'); ?></label>
                            <div class="cjs-datetime-pair">
                                <input type="date" id="cjs-ci-end-date" />
                                <input type="text" inputmode="numeric" class="cjs-time-text" id="cjs-ci-end-time" placeholder="HH:MM" />
                            </div>
                        </div>
                    </div>

                    <div class="cjs-form-row" id="cjs-ci-deadline-row" style="display:none;">
                        <div id="cjs-ci-deadline-info" class="cjs-ci-deadline-info"></div>
                    </div>

                    <div class="cjs-form-row cjs-ci-color-row">
                        <label class="cjs-inline-label">
                            <input type="checkbox" id="cjs-ci-color-custom" />
                            <?php _e('Atrakinti spalvą', 'custom-jewelry-system'); ?>
                        </label>
                        <span class="cjs-color-wrap cjs-color-locked" id="cjs-ci-color-wrap">
                            <input type="color" id="cjs-ci-color" value="#4285f4" disabled />
                        </span>
                    </div>

                    <div class="cjs-form-row cjs-ci-flags">
                        <label class="cjs-inline-label">
                            <input type="checkbox" id="cjs-ci-locked" />
                            <?php _e('Užrakinta', 'custom-jewelry-system'); ?>
                        </label>
                        <label class="cjs-inline-label" id="cjs-ci-done-wrap">
                            <input type="checkbox" id="cjs-ci-done" />
                            <?php _e('Atlikta', 'custom-jewelry-system'); ?>
                        </label>
                    </div>

                    <div class="cjs-form-row" id="cjs-ci-order-row" style="display:none;">
                        <a href="#" id="cjs-ci-order-link" target="_blank" class="button"><span class="dashicons dashicons-external"></span> <?php _e('Atidaryti užsakymų sąraše', 'custom-jewelry-system'); ?></a>
                    </div>

                    <div class="cjs-form-row" id="cjs-ci-split-row" style="display:none;">
                        <label for="cjs-ci-split-time"><?php _e('Padalinti ties', 'custom-jewelry-system'); ?></label>
                        <div class="cjs-ci-split-controls">
                            <input type="text" inputmode="numeric" class="cjs-time-text" id="cjs-ci-split-time" placeholder="HH:MM" />
                            <button type="button" class="button" id="cjs-ci-split-btn"><?php _e('Padalinti', 'custom-jewelry-system'); ?></button>
                        </div>
                    </div>

                    <div class="cjs-modal-error" id="cjs-ci-error" style="display:none;"></div>

                    <div class="cjs-form-actions cjs-ci-actions">
                        <button type="button" class="button cjs-ci-delete-btn" id="cjs-ci-delete" style="display:none;"><?php _e('Ištrinti', 'custom-jewelry-system'); ?></button>
                        <span class="cjs-ci-actions-right">
                            <button type="button" class="button cjs-modal-cancel"><?php _e('Atšaukti', 'custom-jewelry-system'); ?></button>
                            <button type="button" class="button button-primary" id="cjs-ci-save"><?php _e('Išsaugoti', 'custom-jewelry-system'); ?></button>
                        </span>
                    </div>
                </div>
            </div>

            <div id="cjs-calendar-settings-modal" class="cjs-modal">
                <div class="cjs-modal-content cjs-settings-modal-content">
                    <span class="cjs-modal-close">&times;</span>
                    <h2><?php _e('Kalendoriaus nustatymai', 'custom-jewelry-system'); ?></h2>

                    <h3><?php _e('Darbo savaitė', 'custom-jewelry-system'); ?></h3>
                    <div id="cjs-cs-week"></div>

                    <h3><?php _e('Privalomas poilsis', 'custom-jewelry-system'); ?></h3>
                    <div class="cjs-form-row cjs-cs-rest-required">
                        <label for="cjs-cs-rest-hours"><?php _e('Privalomo poilsio valandos per parą', 'custom-jewelry-system'); ?></label>
                        <input type="number" id="cjs-cs-rest-hours" step="0.5" min="0" max="23" />
                        <span id="cjs-cs-rest-usage"></span>
                    </div>
                    <div id="cjs-cs-rest"></div>
                    <button type="button" class="button" id="cjs-cs-rest-add"><?php _e('+ Poilsio laikas', 'custom-jewelry-system'); ?></button>

                    <h3><?php _e('Planavimas', 'custom-jewelry-system'); ?></h3>
                    <div class="cjs-form-row">
                        <label for="cjs-cs-algorithm"><?php _e('Algoritmas', 'custom-jewelry-system'); ?></label>
                        <select id="cjs-cs-algorithm">
                            <option value="fcfs"><?php _e('Eiliškumas pagal terminą (FCFS)', 'custom-jewelry-system'); ?></option>
                            <option value="sjf"><?php _e('Trumpiausias darbas pirmiau (SJF)', 'custom-jewelry-system'); ?></option>
                            <option value="rr"><?php _e('Round Robin (du darbai per dieną)', 'custom-jewelry-system'); ?></option>
                        </select>
                    </div>
                    <div class="cjs-form-row">
                        <label for="cjs-cs-overflow"><?php _e('Netelpantis darbas', 'custom-jewelry-system'); ?></label>
                        <select id="cjs-cs-overflow">
                            <option value="delay"><?php _e('Vėlinti užsakymus', 'custom-jewelry-system'); ?></option>
                            <option value="increase"><?php _e('Didinti darbo valandas', 'custom-jewelry-system'); ?></option>
                        </select>
                    </div>
                    <div class="cjs-form-row" id="cjs-cs-aggro-row">
                        <label for="cjs-cs-aggro"><?php _e('Valandų didinimo lygis', 'custom-jewelry-system'); ?></label>
                        <select id="cjs-cs-aggro">
                            <option value="1"><?php _e('1 - ilginti darbo dienas', 'custom-jewelry-system'); ?></option>
                            <option value="2"><?php _e('2 - dirbti ir poilsio dienomis', 'custom-jewelry-system'); ?></option>
                            <option value="3"><?php _e('3 - palikti tik ilgiausią pertrauką', 'custom-jewelry-system'); ?></option>
                            <option value="4"><?php _e('4 - be pertraukų', 'custom-jewelry-system'); ?></option>
                        </select>
                    </div>
                    <div class="cjs-form-row">
                        <label for="cjs-cs-autosplit"><?php _e('Automatiškai skaidyti darbą į segmentus', 'custom-jewelry-system'); ?></label>
                        <select id="cjs-cs-autosplit">
                            <option value="0"><?php _e('Išjungta', 'custom-jewelry-system'); ?></option>
                            <option value="30"><?php _e('30 min', 'custom-jewelry-system'); ?></option>
                            <option value="60"><?php _e('1 val.', 'custom-jewelry-system'); ?></option>
                            <option value="120"><?php _e('2 val.', 'custom-jewelry-system'); ?></option>
                            <option value="240"><?php _e('4 val.', 'custom-jewelry-system'); ?></option>
                        </select>
                    </div>

                    <div class="cjs-modal-error" id="cjs-cs-error" style="display:none;"></div>

                    <div class="cjs-form-actions">
                        <button type="button" class="button cjs-modal-cancel"><?php _e('Atšaukti', 'custom-jewelry-system'); ?></button>
                        <button type="button" class="button button-primary" id="cjs-cs-save"><?php _e('Išsaugoti', 'custom-jewelry-system'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
