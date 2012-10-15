(function($, _, init){

	var ajaxUrl  = init.ajaxUrl  || "",
		post_id  = init.post_id  || 0,
		since_id = init.since_id || 0;

	var Comment = Backbone.Model.extend({
		idAttribute: "comment_ID"
	});

	var commentTemplate = _.template($('script#wp-live-comment-template').html());

	var CommentCollection = Backbone.Collection.extend({
		model: Comment,
		since_id: 0,
		initialize: function() {
			this.since_id = since_id;
		},
		_setSinceID: function(comments) {
			if (comments.length) {
				this.since_id = _.max(_.pluck(comments, "comment_ID"));
			}
		},
		url : function() {
			return ajaxUrl + "?" + $.param({action: "json_comments", post_id: post_id, since_id: this.since_id});
		},
		parse: function(response) {
			this._setSinceID(response);
			return response;
		}
	});

	var CommentView = Backbone.View.extend({
		tagName: "li",
		attributes: {
			"class": "comment"
		},
		events: {
			"click a.comment-reply-link": "reply"
		},
		reply: function() {
			addComment.moveForm(("comment-" + this.model.id), this.model.id, "respond", post_id);
			return false;
		},
		render: function() {
			this.$el.addClass(this.model.get("comment_class"));
			this.$el.html(commentTemplate(this.model.toJSON()));
			return this;
		}
	});

	var CommentCollectionView = Backbone.View.extend({
		el: "#comments ol.commentlist",
		_commentViews: [],
		initialize: function() {
			this.collection.on("add", this.add, this);
			this.collection.on("reset", this.reset, this);
		},
		add: function(comment) {
			var commentView = new CommentView({model: comment});

			this._commentViews.push(commentView);

			var commentParent = parseInt(comment.get("comment_parent"));

			if (!isNaN(commentParent) && (commentParent > 0)) {

				var parentCommentView = _.filter(this._commentViews, function(commentView) {
					return commentParent === parseInt(commentView.model.id);
				}).pop();

				if ("undefined" !== typeof(parentCommentView)) {

					parentCommentView.$("> ul.children").append(commentView.render().el);

					return;

				}

			}

			this.$el.append(commentView.render().el);

		},
		reset: function(collection) {
			_.map(collection.models, this.add, this);
		}
	});

	var theComments = new CommentCollection();
	window.bbLiveComments = theComments;

	var theCommentsCollectionView = new CommentCollectionView({
		collection: theComments
	});

	if (_.isArray(init.comments)) {
		theComments.reset(init.comments);
	}

	setInterval(function(){
		var options = {
			add: true
		};
		// IE7 holds on to AJAX responses more aggressively than other browsers
		if ($.browser.msie) {
			options.cache = false;
		}
		theComments.fetch(options);
	}, 2000);

	// AJAX comment form submit

	var $commentForm = $("#commentform"),
		$commentFormError = $("#comment-form-error");

	var setCommentFormError = function(message) {
		$commentFormError.html(message);
	};

	$commentForm.ajaxForm({
		dataType: "json",
		beforeSubmit: function(form_data, $form, options) {

			var empty_required_fields = $form.find(':input[aria-required="true"]').filter(function(){
				return $.trim($(this).val()) === "";
			});

			if (empty_required_fields.length > 0) {

				setCommentFormError("Please fill out all required fields.");
				return false;
			}

		},
		success: function(data) {

			if ("undefined" === typeof(data.error)) {

				theComments.add(data);
				$commentForm.clearForm();
				$("#cancel-comment-reply-link").click();

			}

			setCommentFormError(data.error || "");

		},
		error: function() {
			setCommentFormError("There was an error processing your request.");
		}
	});

})(jQuery, _, window.wpLiveCommentsInit || {});