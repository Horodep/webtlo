
/* работа с топиками */

$(document).ready(function () {

	// скачивание т.-файлов выделенных топиков
	$(".tor_download").on("click", function () {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResult("Выберите раздачи");
			return false;
		}
		var forum_id = $("#subsections").val();
		var replace_passkey = $(this).val();
		var config = $("#config").serialize();
		$("#process").text("Скачивание торрент-файлов...");
		$.ajax({
			type: "POST",
			context: this,
			url: "php/actions/get_torrent_files.php",
			data: {
				cfg: config,
				topics_ids: topics_ids,
				forum_id: forum_id,
				replace_passkey: replace_passkey
			},
			beforeSend: function () {
				block_actions();
			},
			complete: function () {
				block_actions();
			},
			success: function (response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				showResult(response.result);
			},
		});
	});

	// "чёрный" список раздач
	$("#tor_blacklist").on("click", function () {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResult("Выберите раздачи");
			return false;
		}
		var forum_id = $("#subsections").val();
		var value = forum_id != -2 ? 1 : 0;
		$("#process").text('Редактирование "чёрного списка" раздач...');
		$.ajax({
			type: "POST",
			url: "php/actions/blacklist.php",
			data: {
				topics_ids: topics_ids,
				value: value
			},
			beforeSend: function () {
				block_actions();
			},
			complete: function () {
				block_actions();
			},
			success: function (response) {
				showResult(response);
				getFilteredTopics();
			}
		});
	});

	// добавление раздач в торрент-клиент
	$("#tor_add").on("click", function () {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResult("Выберите раздачи");
			return;
		}
		var forums = getForums();
		if ($.isEmptyObject(forums)) {
			showResult("В настройках не найдены подразделы");
			return;
		}
		var tor_clients = getTorClients();
		if ($.isEmptyObject(tor_clients)) {
			showResult("В настройках не найдены торрент-клиенты");
			return;
		}
		$("#process").text("Добавление раздач в торрент-клиент...");
		var config = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "php/actions/add_topics_to_client.php",
			data: {
				cfg: config,
				topics_ids: topics_ids,
				tor_clients: tor_clients,
				forums: forums
			},
			beforeSend: function () {
				block_actions();
			},
			complete: function () {
				block_actions();
			},
			success: function (response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				showResult(response.result);
				getFilteredTopics();
			}
		});
	});

	// управление раздачами (старт, стоп и т.п.)
	$(".torrent_action").on("click", function (e) {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResult("Выберите раздачи");
			return;
		}
		var tor_clients = getTorClients();
		if ($.isEmptyObject(tor_clients)) {
			showResult("В настройках не найдены торрент-клиенты");
			return;
		}
		var action = $(this).val();
		var subsection = $("#subsections").val();
		var label = "";
		var remove_data = "";
		var force_start = "";
		if (subsection > 0) {
			var data = $("#list-ss [value=" + subsection + "]").attr("data");
			data = data.split("|");
			label = data[1];
		}
		if (action == "remove") {
			$("#dialog").dialog(
				{
					buttons: [
						{
							text: "Да",
							click: function () {
								remove_data = true;
								execActionTopics(
									topics_ids,
									tor_clients,
									action,
									label,
									force_start,
									remove_data
								);
							}
						},
						{
							text: "Нет",
							click: function () {
								execActionTopics(
									topics_ids,
									tor_clients,
									action,
									label,
									force_start,
									remove_data
								);
							}
						}
					],
					modal: true,
					resizable: false,
					// position: [ 'center', 200 ]
				}
			).text('Удалить загруженные файлы раздач с диска ?');
			$("#dialog").dialog("open");
			return true;
		}
		if (
			action == "set_label"
			&& (
				e.ctrlKey
				|| subsection == 0
			)
		) {
			$("#dialog").dialog(
				{
					buttons: [
						{
							text: "ОК",
							click: function () {
								label = $("#any_label").val();
								execActionTopics(
									topics_ids,
									tor_clients,
									action,
									label,
									force_start,
									remove_data
								);
							}
						}
					],
					modal: true,
					resizable: false,
					// position: [ 'center', 200 ]
				}
			).html('<label>Установить метку: <input id="any_label" size="27" />');
			$("#dialog").dialog("open");
			return true;
		}
		execActionTopics(
			topics_ids,
			tor_clients,
			action,
			label,
			force_start,
			remove_data
		);
	});

	// кнопка выделить все / отменить выделение
	$(".tor_select").on("click", function () {
		var value = $(this).val();
		$("#topics").find(".topic[type=checkbox]").prop("checked", Boolean(value));
		getCountSizeSelectedTopics();
	});

	// выделение/снятие выделения интервала раздач
	$("#topics").on("click", ".topic", function (event) {
		if (!$("#topics .topic").hasClass("first-topic")) {
			$(this).addClass("first-topic");
			getCountSizeSelectedTopics();
			return false;
		}
		if (event.shiftKey) {
			var data = this.dataset;
			var tag = parseInt(data.tag); // 2 - 20 = -18; 10 - 2 = 8;
			var data_first = document.querySelector("#topics .first-topic").dataset;
			var tag_first = parseInt(data_first.tag);
			var direction = tag_first - tag < 0 ? "down" : "up";
			$("#topics").closest("form").find(".topic[type=checkbox]").each(function () {
				var data_this = this.dataset;
				var tag_this = parseInt(data_this.tag);
				if (direction == "down") {
					if (
						tag_this >= tag_first
						&& tag_this <= tag
					) {
						$(this).prop("checked", !event.ctrlKey);
					}
				}
				if (direction == "up") {
					if (
						tag_this <= tag_first
						&& tag_this >= tag
					) {
						$(this).prop("checked", !event.ctrlKey);
					}
				}
			});
		}
		$("#topics .first-topic").removeClass("first-topic");
		$(this).addClass("first-topic");
		getCountSizeSelectedTopics();
	});

	// скрыть/показать фильтр
	$("#filter_show").on("click", function () {
		$("#topics_filter").toggle(500, function () {
			Cookies.set('filter-state', $(this).is(':visible'));
		});
	});

	// сбросить настройки фильтра
	$("#filter_reset").on("click", function () {
		$("#topics_filter input[type=text]").val("");
		$("#topics_filter input[type=search]").val("");
		$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
		$("#filter_date_release").datepicker("setDate", "-" + $("#rule_date_release").val());
		$("#filter_rule, #filter_rule_to").val($("#rule_topics").val());
		$("#filter_rule_from").val(0);
		$("#filter_avg_seeders_period").val($("#avg_seeders_period").val());
		$(".filter_rule_interval").hide();
		$(".filter_rule_one").show();
		$("#topics_filter .default").prop("checked", true).change();
	});

	// вкл/выкл интервал сидов
	$("input[name=filter_interval]").on("change", function () {
		$(".filter_rule_interval").toggle(500);
		$(".filter_rule_one").toggle(500);
	});

	// события при выборе свойств фильтра
	$("#topics_filter").find("input[type=text], input[type=search]").on("spin input", function () {
		filter_delay(getFilteredTopics);
	});

	$("#topics_filter input[type=radio], #topics_filter input[type=checkbox], #filter_date_release").on("change", function () {
		filter_delay(getFilteredTopics);
	});

	// есть/нет хранители
	$(".topics_filter .keepers").on("change", function () {
		if ($(this).prop("checked")) {
			switch ($(this).attr('name')) {
				case 'not_keepers':
					$("input[name=is_keepers]").prop("checked", false);
					break;
				case 'is_keepers':
					$("input[name=not_keepers]").prop("checked", false);
					break;
			}
		}
	});

	// ник хранителя в поиск при двойном клике
	$("#topics").on("dblclick", ".keeper", function (e) {
		$("input[name=filter_phrase]").val($(this).text());
		$('input[name=filter_by_phrase][type="radio"]').prop("checked", false);
		$('#filter_by_keeper').prop("checked", true);
		$('input[name=is_keepers][type="checkbox"]').prop("checked", true).change();
	});

	// загрузка параметров фильтра из кук
	var filter_state = Cookies.get("filter-state");
	var filter_options = Cookies.get("filter-options");
	if (filter_state === "false") {
		$("#topics_filter").hide();
	}
	if (typeof filter_options !== "undefined") {
		filter_options = $.parseJSON(filter_options);
		$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
		$.each(filter_options, function (i, option) {
			$("#topics_filter input[name='" + option.name + "']").each(function () {
				if ($(this).val() === option.value) {
					if (
						$(this).attr("type") === "checkbox"
						|| $(this).attr("type") === "radio"
					) {
						$(this).prop("checked", true).change();
					}
					$(this).val(option.value);
				}
			});
		});
	} else {
		getFilteredTopics();
	}

});

// задержка при выборе свойств фильтра
var filter_delay = makeDelay(500);

// получение отфильтрованных раздач из базы
function getFilteredTopics() {
	var forum_id = $("#subsections").val();
	// блокировка фильтра
	if (
		forum_id > 0
		|| forum_id == -3
	) {
		$(".topics_filter input").prop("disabled", false);
		$("#toolbar-new-torrents button").button("enable");
		$("#toolbar-control-topics button").button("enable");
		$("#filter_avg_seeders_period").spinner("enable");
		$("#filter_rule").spinner("enable");
		$("#filter_rule_from").spinner("enable");
		$("#filter_rule_to").spinner("enable");
		$("#filter_date_release").datepicker("enable");
	} else {
		if (forum_id == -2) {
			$("#toolbar-control-topics button").button("disable");
		}
		$(".topics_filter input").prop("disabled", true);
		$("#filter-sort-block input").prop("disabled", false);
		$("#toolbar-new-torrents button").button("disable");
		$("#filter_avg_seeders_period").spinner("disable");
		$("#filter_rule").spinner("disable");
		$("#filter_rule_from").spinner("disable");
		$("#filter_rule_to").spinner("disable");
		$("#filter_date_release").datepicker("disable");
	}
	// запоминаем параметры фильтра в куки
	Cookies.set("filter-options", $("#topics_filter").serializeAllArray());
	// сериализим параметры фильтра
	var $filter = $("#topics_filter").serialize();
	$("#process").text("Получение данных о раздачах...");
	$.ajax({
		type: "POST",
		url: "php/actions/get_filtered_list_topics.php",
		data: {
			forum_id: forum_id,
			filter: $filter,
		},
		beforeSend: function () {
			block_actions();
		},
		complete: function () {
			block_actions();
		},
		success: function (response) {
			response = $.parseJSON(response);
			if (response.log.length) {
				$("#topics_result").text(response.log);
			}
			if (response.topics != null) {
				$("#topics").html(response.topics);
				$("#filtered_topics_count").text(response.count);
				$("#filtered_topics_size").text(сonvertBytes(response.size));
			}
			showCountSizeSelectedTopics();
		}
	});
}

// вывод на экран кол-во, объём выделенных раздач
function showCountSizeSelectedTopics(count = 0, size = 0.00) {
	$("#topics_count").text(count);
	$("#topics_size").text(сonvertBytes(size));
}

// получение кол-ва, объёма выделенных раздач
function getCountSizeSelectedTopics() {
	var count = 0;
	var size = 0.00;
	var topics = $("#topics").find(".topic[type=checkbox]:checked");
	if (!$.isEmptyObject(topics)) {
		topics.each(function () {
			var data = this.dataset;
			size += parseInt(data.size);
			count++;
		});
	}
	showCountSizeSelectedTopics(count, size);
}

// действия с выбранными раздачами (старт, стоп, метка, удалить)
function execActionTopics(topics_ids, tor_clients, action, label, force_start, remove_data) {
	$("#dialog").dialog("close");
	$("#process").text("Управление раздачами...");
	$.ajax({
		type: "POST",
		context: this,
		url: "php/actions/exec_actions_topics.php",
		data: {
			topics_ids: topics_ids,
			tor_clients: tor_clients,
			action: action,
			remove_data: remove_data,
			force_start: force_start,
			label: label
		},
		beforeSend: function () {
			block_actions();
		},
		complete: function () {
			block_actions();
		},
		success: function (response) {
			response = $.parseJSON(response);
			$("#log").append(response.log);
			showResult(response.result);
			if (action == 'remove') {
				getFilteredTopics();
			}
		}
	});
}
