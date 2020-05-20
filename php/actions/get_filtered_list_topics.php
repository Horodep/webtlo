<?php

try {
    include_once dirname(__FILE__) . '/../common.php';

    if (isset($_POST['forum_id'])) {
        $forum_id = $_POST['forum_id'];
    }

    if (
        !isset($forum_id)
        || !is_numeric($forum_id)
    ) {
        throw new Exception('Некорректный идентификатор подраздела: ' . $forum_id);
    }

    // получаем настройки
    $cfg = get_settings();

    // кодировка для regexp
    mb_regex_encoding('UTF-8');

    // парсим параметры фильтра
    parse_str($_POST['filter'], $filter);

    if (!isset($filter['filter_sort'])) {
        throw new Exception('Не выбрано поле для сортировки');
    }

    if (!isset($filter['filter_sort_direction'])) {
        throw new Exception('Не выбрано направление сортировки');
    }

    // 0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые
    // -4 - дублирующиеся раздачи
    // -5 - высокоприоритетные раздачи

    // topic_data => tag,id,na,si,convert(si)rg,se,ds
    $pattern_topic_block = '<div class="topic_data"><label>%s</label> %s</div>';
    $pattern_topic_data = array(
        'id' => '<input type="checkbox" name="topics_ids[]" class="topic" value="%2$s" data-size="%4$s" data-tag="%1$s">',
        'ds' => ' <i class="fa %9$s %8$s"></i>',
        'rg' => ' | <span>%6$s | </span> ',
        'na' => '<a href="' . $cfg['forum_address'] . '/forum/viewtopic.php?t=%2$s" target="_blank">%3$s</a>',
        'si' => ' (%5$s)',
        'se' => ' - <span class="text-danger">%7$s</span>',
    );

    $output = '';
    $filtered_topics_count = 0;
    $filtered_topics_size = 0;

    if ($forum_id == 0) {
        // сторонние раздачи
        $topics = Db::query_database(
            'SELECT id,na,si,rg,ss,se FROM TopicsUntracked',
            array(),
            true
        );
        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );
        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            $data = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $filtered_topics_count,
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    $topic_data['se']
                ),
                '<span class="bold">#' . $topic_data['ss'] . '</span>'
            );
        }
    } elseif ($forum_id == -2) {
        // находим значение за последний день
        $se = $cfg['avg_seeders'] ? '(se * 1.) / qt as se' : 'se';
        // чёрный список
        $topics = Db::query_database(
            'SELECT Topics.id,na,si,rg,' . $se . ',comment FROM Topics
			LEFT JOIN Blacklist ON Topics.id = Blacklist.id
			WHERE Blacklist.id IS NOT NULL',
            array(),
            true
        );
        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );
        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            $data = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $filtered_topics_count,
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    round($topic_data['se'])
                ),
                '<span class="bold">' . $topic_data['comment'] . '</span>'
            );
        }
    } elseif ($forum_id == -4) {
        // дублирующиеся раздачи
        $statementFields = array();
        $statementLeftJoin = array();
        if ($cfg['avg_seeders']) {
            if (!is_numeric($filter['avg_seeders_period'])) {
                throw new Exception('В фильтре введено некорректное значение для периода средних сидов');
            }
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] > 0 ? $filter['avg_seeders_period'] : 1;
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] <= 30 ? $filter['avg_seeders_period'] : 30;
            for ($dayNumber = 0; $dayNumber < $filter['avg_seeders_period']; $dayNumber++) {
                $statementTotal['seeders'][] = 'CASE WHEN d' . $dayNumber . ' IS "" OR d' . $dayNumber . ' IS NULL THEN 0 ELSE d' . $dayNumber . ' END';
                $statementTotal['updates'][] = 'CASE WHEN q' . $dayNumber . ' IS "" OR q' . $dayNumber . ' IS NULL THEN 0 ELSE q' . $dayNumber . ' END';
                $statementTotal['values'][] = 'CASE WHEN q' . $dayNumber . ' IS "" OR q' . $dayNumber . ' IS NULL THEN 0 ELSE 1 END';
            }
            $statementTotalValues = implode('+', $statementTotal['values']);
            $statementTotalUpdates = implode('+', $statementTotal['updates']);
            $statementTotalSeeders = implode('+', $statementTotal['seeders']);
            $statementAverageSeeders = 'CASE WHEN ' . $statementTotalValues . ' IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + ' . $statementTotalSeeders . ') / ( qt + ' . $statementTotalUpdates . ') END';
            $statementFields = array(
                $statementTotalValues . ' as ds',
                $statementAverageSeeders . ' as se'
            );
            $statementLeftJoin[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';
        } else {
            $statementFields[] = 'se';
        }
        $statementSQL = 'SELECT Topics.id,hs,na,si,rg%s FROM Topics %s
            WHERE Topics.hs IN (SELECT hs FROM Clients GROUP BY hs HAVING count(*) > 1)';
        $statement = sprintf(
            $statementSQL,
            ',' . implode(',', $statementFields),
            ' ' . implode(' ', $statementLeftJoin)
        );
        $topicsData = Db::query_database($statement, array(), true);
        $topicsData = natsort_field(
            $topicsData,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );
        foreach ($topicsData as $topicID => $topicData) {
            $outputLine = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topicData['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topicData[$field])) {
                    $outputLine .= $pattern;
                }
            }
            $stateAverageSeeders = '';
            if (isset($topicData['ds'])) {
                if ($topicData['ds'] < $filter['avg_seeders_period']) {
                    $stateAverageSeeders = $topicData['ds'] >= $filter['avg_seeders_period'] / 2 ? 'text-warning' : 'text-danger';
                } else {
                    $stateAverageSeeders = 'text-success';
                }
            }
            $statement = 'SELECT cl,dl FROM Clients WHERE hs = ? ORDER BY cl';
            $listTorrentClientsIDs = Db::query_database(
                $statement,
                array($topicData['hs']),
                true
            );
            // сортировка торрент-клиентов
            $sortOrderTorrentClients = array_flip(array_keys($cfg['clients']));
            usort($listTorrentClientsIDs, function ($a, $b) use ($sortOrderTorrentClients) {
                return $sortOrderTorrentClients[$a['cl']] - $sortOrderTorrentClients[$b['cl']];
            });
            $formatTorrentClientList = '<i class="fa fa-%1$s text-%2$s"></i> <i class="bold text-%2$s">%3$s</i>';
            $listTorrentClientsNames = array_map(function ($e) use ($cfg, $formatTorrentClientList) {
                if (isset($cfg['clients'][$e['cl']])) {
                    if ($e['dl'] == '1') {
                        $stateTorrentClientStatus = 'arrow-up';
                        $stateTorrentClientColor = 'success';
                    } elseif ($e['dl'] == '0') {
                        $stateTorrentClientStatus = 'arrow-down';
                        $stateTorrentClientColor = 'danger';
                    } elseif ($e['dl'] == '-1') {
                        $stateTorrentClientStatus = 'pause';
                        $stateTorrentClientColor = 'success';
                    } else {
                        $stateTorrentClientStatus = 'times';
                        $stateTorrentClientColor = 'danger';
                    }
                    return sprintf(
                        $formatTorrentClientList,
                        $stateTorrentClientStatus,
                        $stateTorrentClientColor,
                        $cfg['clients'][$e['cl']]['cm']
                    );
                }
            }, $listTorrentClientsIDs);
            $listTorrentClientsNames = '| ' . implode(', ', $listTorrentClientsNames);
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $outputLine,
                    $filtered_topics_count,
                    $topicData['id'],
                    $topicData['na'],
                    $topicData['si'],
                    convert_bytes($topicData['si']),
                    date('d.m.Y', $topicData['rg']),
                    round($topicData['se']),
                    $stateAverageSeeders,
                    'fa-circle'
                ),
                $listTorrentClientsNames
            );
        }
    } elseif (
        $forum_id == -3
        || $forum_id > 0
        || $forum_id == -5
    ) {
        // все хранимые раздачи
        // не выбраны статусы раздач
        if (empty($filter['filter_tracker_status'])) {
            throw new Exception('Не выбраны статусы раздач для трекера');
        }

        if (empty($filter['keeping_priority'])) {
            if ($forum_id == -5) {
                $filter['keeping_priority'] = array(2);
            } else {
                throw new Exception('Не выбраны приоритеты раздач для трекера');
            }
        }

        if (empty($filter['filter_client_status'])) {
            throw new Exception('Не выбраны статусы раздач для торрент-клиента');
        }

        // некорретный ввод значения сидов
        if (isset($filter['filter_interval'])) {
            if (
                !is_numeric($filter['filter_rule_interval']['from'])
                || !is_numeric($filter['filter_rule_interval']['to'])
            ) {
                throw new Exception('В фильтре введено некорректное значение сидов');
            }
            if (
                $filter['filter_rule_interval']['from'] < 0
                || $filter['filter_rule_interval']['to'] < 0
            ) {
                throw new Exception('Значение сидов в фильтре должно быть больше 0');
            }
            if ($filter['filter_rule_interval']['from'] > $filter['filter_rule_interval']['to']) {
                throw new Exception('Начальное значение сидов в фильтре должно быть меньше или равно конечному значению');
            }
        } else {
            if (!is_numeric($filter['filter_rule'])) {
                throw new Exception('В фильтре введено некорректное значение сидов');
            }

            if ($filter['filter_rule'] < 0) {
                throw new Exception('Значение сидов в фильтре должно быть больше 0');
            }
        }

        // некорректная дата
        $date_release = DateTime::createFromFormat('d.m.Y', $filter['filter_date_release']);
        if (!$date_release) {
            throw new Exception('В фильтре введена некорректная дата создания релиза');
        }

        // хранимые подразделы
        if ($forum_id > 0) {
            $forums_ids = array($forum_id);
        } elseif ($forum_id == -5) {
            $forums_ids = Db::query_database(
                'SELECT DISTINCT(ss) FROM Topics WHERE pt = 2',
                array(),
                true,
                PDO::FETCH_COLUMN
            );
            if (empty($forums_ids)) {
                $forums_ids = array(0);
            }
        } else {
            if (isset($cfg['subsections'])) {
                foreach ($cfg['subsections'] as $forum_id => $subsection) {
                    if (!$subsection['hide_topics']) {
                        $forums_ids[] = $forum_id;
                    }
                }
            } else {
                $forums_ids = array(0);
            }
        }

        $ss = str_repeat('?,', count($forums_ids) - 1) . '?';
        $st = str_repeat('?,', count($filter['filter_tracker_status']) - 1) . '?';
        $dl = 'dl IS NOT -2 AND abs(dl) IS ' . implode(' OR abs(dl) IS ', $filter['filter_client_status']);

        // 1 - fields, 2 - left join, 3 - where
        $pattern_statement = 'SELECT Topics.id,na,si,rg,pt,dl%s FROM Topics
			LEFT JOIN Clients ON Topics.hs = Clients.hs%s
			LEFT JOIN (SELECT * FROM Keepers GROUP BY id) Keepers ON Topics.id = Keepers.id
			LEFT JOIN (SELECT * FROM Blacklist GROUP BY id) Blacklist ON Topics.id = Blacklist.id
			WHERE ss IN (' . $ss . ') AND st IN (' . $st . ') AND (' . $dl . ') AND Blacklist.id IS NULL%s';

        $fields = array();
        $where = array();
        $left_join = array();

        if ($cfg['avg_seeders']) {
            // некорректный период средних сидов
            if (!is_numeric($filter['avg_seeders_period'])) {
                throw new Exception('В фильтре введено некорректное значение для периода средних сидов');
            }
            // жёсткое ограничение на 30 дней для средних сидов
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] > 0 ? $filter['avg_seeders_period'] : 1;
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] <= 30 ? $filter['avg_seeders_period'] : 30;
            for ($i = 0; $i < $filter['avg_seeders_period']; $i++) {
                $avg['sum_se'][] = 'CASE WHEN d' . $i . ' IS "" OR d' . $i . ' IS NULL THEN 0 ELSE d' . $i . ' END';
                $avg['sum_qt'][] = 'CASE WHEN q' . $i . ' IS "" OR q' . $i . ' IS NULL THEN 0 ELSE q' . $i . ' END';
                $avg['qt'][] = 'CASE WHEN q' . $i . ' IS "" OR q' . $i . ' IS NULL THEN 0 ELSE 1 END';
            }
            $qt = implode('+', $avg['qt']);
            $sum_qt = implode('+', $avg['sum_qt']);
            $sum_se = implode('+', $avg['sum_se']);
            $avg = 'CASE WHEN ' . $qt . ' IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + ' . $sum_se . ') / ( qt + ' . $sum_qt . ') END';

            $fields[] = $qt . ' as ds';
            $fields[] = $avg . ' as se';
            $left_join[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';
        } else {
            $fields[] = 'se';
        }

        // есть/нет хранители
        if (isset($filter['not_keepers'])) {
            $where[] = 'AND ( rg > posted OR Keepers.id IS NULL )';
        } elseif (isset($filter['is_keepers'])) {
            $where[] = 'AND Keepers.id IS NOT NULL AND rg < posted';
        }

        // данные о других хранителях
        $keepers = Db::query_database(
            'SELECT id,nick,complete FROM Keepers WHERE id IN (
                SELECT Topics.id FROM Topics
                LEFT JOIN Keepers ON Topics.id = Keepers.id
                WHERE ss IN (' . $ss . ') AND rg < posted
            )',
            $forums_ids,
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_GROUP
        );

        $statement = sprintf(
            $pattern_statement,
            ',' . implode(',', $fields),
            ' ' . implode(' ', $left_join),
            ' ' . implode(' ', $where)
        );

        // из базы
        $topics = Db::query_database(
            $statement,
            array_merge(
                $forums_ids,
                $filter['filter_tracker_status']
            ),
            true
        );

        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );

        // фильтрация по фразе е=ё
        if (!empty($filter['filter_phrase'])) {
            $filter['filter_phrase'] = preg_replace(
                '/[её]/ui',
                '(е|ё)',
                quotemeta($filter['filter_phrase'])
            );
        }

        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            // фильтрация по приоритету
            if (!in_array($topic_data['pt'], $filter['keeping_priority'])) {
                continue;
            }
            // фильтрация по дате релиза
            if ($topic_data['rg'] > $date_release->format('U')) {
                continue;
            }
            // фильтрация по количеству сидов
            if (isset($filter['filter_interval'])) {
                if (
                    $filter['filter_rule_interval']['from'] > $topic_data['se']
                    || $filter['filter_rule_interval']['to'] < $topic_data['se']
                ) {
                    continue;
                }
            } else {
                if ($filter['filter_rule_direction']) {
                    if ($filter['filter_rule'] < $topic_data['se']) {
                        continue;
                    }
                } else {
                    if ($filter['filter_rule'] > $topic_data['se']) {
                        continue;
                    }
                }
            }
            // фильтрация по статусу "зелёные"
            if (
                isset($topic_data['ds'])
                && isset($filter['avg_seeders_complete'])
                && $filter['avg_seeders_period'] > $topic_data['ds']
            ) {
                continue;
            }
            // список хранителей на раздаче
            $keepers_list = '';
            if (isset($keepers[$topic_data['id']])) {
                $formatKeeperList = '<i class="fa fa-arrow-%1$s text-%2$s"></i> <i class="keeper bold text-%2$s">%3$s</i>';
                $keepers_list = array_map(function ($e) use ($formatKeeperList) {
                    if ($e['complete'] == 1) {
                        $stateKeeperArrow = 'up';
                        $stateKeeperColor = 'success';
                    } else {
                        $stateKeeperArrow = 'down';
                        $stateKeeperColor = 'danger';
                    }
                    return sprintf(
                        $formatKeeperList,
                        $stateKeeperArrow,
                        $stateKeeperColor,
                        $e['nick']
                    );
                }, $keepers[$topic_data['id']]);
                $keepers_list = '| ' . implode(', ', $keepers_list);
            }
            // фильтрация по фразе
            if (!empty($filter['filter_phrase'])) {
                if (empty($filter['filter_by_phrase'])) {
                    if (!mb_eregi($filter['filter_phrase'], $keepers_list)) {
                        continue;
                    }
                } else {
                    if (!mb_eregi($filter['filter_phrase'], $topic_data['na'])) {
                        continue;
                    }
                }
            }
            $data = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            // тип пульки: раздаю, качаю, на паузе
            if ($topic_data['dl'] == '1') {
                $stateTorrentClient = 'fa-arrow-up';
            } elseif ($topic_data['dl'] == '0') {
                $stateTorrentClient = 'fa-arrow-down';
            } elseif ($topic_data['dl'] == '-1') {
                $stateTorrentClient = 'fa-pause';
            } else {
                $stateTorrentClient = 'fa-circle';
            }
            // цвет пульки
            $bullet = '';
            if (isset($topic_data['ds'])) {
                if ($topic_data['ds'] < $filter['avg_seeders_period']) {
                    $bullet = $topic_data['ds'] >= $filter['avg_seeders_period'] / 2 ? 'text-warning' : 'text-danger';
                } else {
                    $bullet = 'text-success';
                }
            }
            // выводим строку
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $filtered_topics_count,
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    round($topic_data['se'], 2),
                    $bullet,
                    $stateTorrentClient
                ),
                $keepers_list
            );
        }
    }

    echo json_encode(array(
        'log' => '',
        'topics' => $output,
        'size' => $filtered_topics_size,
        'count' => $filtered_topics_count,
    ));
} catch (Exception $e) {
    echo json_encode(array(
        'log' => $e->getMessage(),
        'topics' => null,
        'size' => 0,
        'count' => 0,
    ));
}
