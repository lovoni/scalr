Ext.ns("Scalr.data");

Scalr.data.ExceptionReporter = function (store, error) {
	Scalr.Viewers.ErrorMessage(error);
}

Scalr.data.ExceptionFormReporter = function (form, action) {
	if (action.result.error) {
		if (Ext.isArray(action.result.error)) {
			for (var i = 0, len = action.result.error.length; i < len; i++)
				Scalr.Viewers.ErrorMessage(action.result.error[i]);
		} else {
			Scalr.Viewers.ErrorMessage(action.result.error);
		}
	} else if (action.result.success && action.result.success != false) {
		Scalr.Viewers.ErrorMessage('Error: ' + action.failureType);
	}
}

Scalr.data.JsonReader = Ext.extend(Ext.data.JsonReader, {
	constructor: function(meta, recordType) {
		meta = meta || {};
		Ext.applyIf(meta, {
			root: 'data',
			successProperty: 'success',
			errorProperty: 'error',
			totalProperty: 'total'
		});
		Scalr.data.JsonReader.superclass.constructor.call(this, meta, recordType);
	},

	readRecords: function (o) {
		var meta = this.meta;

		if (meta.errorProperty) {
			try {
				if (!this.getError) {
					this.getError = this.createAccessor(meta.errorProperty);
				}
				var error = this.getError(o), dataBlock = {};
				if (error) {
					dataBlock[meta.errorProperty] = error;
					dataBlock[meta.successProperty] = false;
					return dataBlock;
				}
			} catch(e) {
				alert(e);
			}
		}
		return Scalr.data.JsonReader.superclass.readRecords.call(this, o);
	}
});

Scalr.data.Store = Ext.extend(Ext.data.Store, {
	constructor: function(config) {
		Scalr.data.Store.superclass.constructor.call(this, config);

		this.addEvents(
			/**
			* @event dataexception
			* Fires if server returns errors understanded by reader.
			*/
			"dataexception"
		);

		this.on('dataexception', Scalr.data.ExceptionReporter);
	},

	loadRecords: function(dataBlock, options, success) {
		if (dataBlock && dataBlock.error) {
			this.fireEvent("dataexception", this, dataBlock.error);
		}

		Scalr.data.Store.superclass.loadRecords.call(this, dataBlock, options, success);
	}
});