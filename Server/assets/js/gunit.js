$(document).ready(function() {
	var intervalObj = null;
	var workStop = parseInt($("input[name='work_stop']").val());
	var instanceId = "";

	if(workStop <= 0) {
		workStop = 20;
	}

	var $instanceIdObj = $("input[name='instance_id']");

	var checkResults = function() {
		$.get("/grank-remote/receiver/getresult", {
			"instance_id": instanceId
		}, function(req) {
			var $debugOut = $("#results");
			$debugOut.text(JSON.stringify(req, null, 4));
			//$debugOut.scrollTop($debugOut[0].scrollHeight - $debugOut.height());

			if(req.results.length >= workStop) {
				clearInterval(intervalObj);
			}
		}, "json");
	};

	var options = {
		beforeSubmit: function() {
			
		},
		success: function(responseText, statusText, xhr, $form)  {
			intervalObj = setInterval(checkResults, 1000);
			instanceId = $instanceIdObj.val();
			$instanceIdObj.val(makeId(8));
		}
	}

	var makeId = function(size) {
		var text = "";
		var possible = "abcdefghijklmnopqrstuvwxyz0123456789";

		for (var i = 0; i < size; i++)
		text += possible.charAt(Math.floor(Math.random() * possible.length));

		return text;
	}

	$("#form_gunit").ajaxForm(options);
});