{literal}
new Scalr.Viewers.FarmRolesEditTab({
	tabTitle: 'Load balancing options',
	layout: 'form',
	labelWidth: 150,

	availZones: {},

	isEnabled: function (record) {
		return record.get('platform') == 'ec2';
	},

	getDefaultValues: function (record) {
		return {
			'lb.use_elb': 0
		};
	},

	activateTab: function () {
		new Ext.ToolTip({
			target: this.findOne('name', 'lb.healthcheck.healthythreshold_help').id,
			dismissDelay: 0,
			html: "The number of consecutive health probe successes required before moving the instance to the Healthy state.<br />The default is 3 and a valid value lies between 2 and 10."
		});

		new Ext.ToolTip({
			target: this.findOne('name', 'lb.healthcheck.interval_help').id,
			dismissDelay: 0,
			html:	"The approximate interval (in seconds) between health checks of an individual instance.<br />The default is 30 seconds and a valid interval must be between 5 seconds and 600 seconds." +
					"Also, the interval value must be greater than the Timeout value"
		});

		new Ext.ToolTip({
			target: this.findOne('name', 'lb.healthcheck.target_help').id,
			dismissDelay: 0,
			html: 	"The instance being checked. The protocol is either TCP or HTTP. The range of valid ports is one (1) through 65535.<br />" +
					'Notes: TCP is the default, specified as a TCP: port pair, for example "TCP:5000".' +
					'In this case a healthcheck simply attempts to open a TCP connection to the instance on the specified port.' +
					'Failure to connect within the configured timeout is considered unhealthy.<br />' +
					'For HTTP, the situation is different. HTTP is specified as a "HTTP:port/PathToPing" grouping, for example "HTTP:80/weather/us/wa/seattle". In this case, a HTTP GET request is issued to the instance on the given port and path. Any answer other than "200 OK" within the timeout period is considered unhealthy.<br />' +
					'The total length of the HTTP ping target needs to be 1024 16-bit Unicode characters or less.'
		});

		new Ext.ToolTip({
			target: this.findOne('name', 'lb.healthcheck.timeout_help').id,
			dismissDelay: 0,
			html:	"Amount of time (in seconds) during which no response means a failed health probe. <br />The default is five seconds and a valid value must be between 2 seconds and 60 seconds." +
					"Also, the timeout value must be less than the Interval value."
		});

		new Ext.ToolTip({
			target: this.findOne('name', 'lb.healthcheck.unhealthythreshold_help').id,
			dismissDelay: 0,
			html: "The number of consecutive health probe failures that move the instance to the unhealthy state.<br />The default is 5 and a valid value lies between 2 and 10."
		});

		this.findOne('itemId', 'listeners').getBottomToolbar().getComponent('lb.bbar.add').on('click', function () {
			var store = this.findOne('itemId', 'listeners_view').store, tbar = this.findOne('itemId', 'listeners').getBottomToolbar(), check = true;

			check = tbar.getComponent('lb.bbar.proto').isValid() && check;
			check = tbar.getComponent('lb.bbar.lb_port').isValid() && check;
			check = tbar.getComponent('lb.bbar.i_port').isValid() && check;

			if (check) {
				var recordData = {
					protocol: tbar.getComponent('lb.bbar.proto').getValue(),
					lb_port: tbar.getComponent('lb.bbar.lb_port').getValue(),
					instance_port: tbar.getComponent('lb.bbar.i_port').getValue()
				};

				if (store.findBy(function (record) {
					if (
						record.get('protocol') == recordData.protocol &&
						record.get('lb_port') == recordData.lb_port &&
						record.get('instance_port') == recordData.instance_port
					) {
						Scalr.Viewers.ErrorMessage('Such listener already exists');
						return true;
					}
				}) == -1) {
					store.add(new store.reader.recordType(recordData));

					tbar.getComponent('lb.bbar.proto').reset();
					tbar.getComponent('lb.bbar.lb_port').reset();
					tbar.getComponent('lb.bbar.i_port').reset();
				}
			}
		}, this);
	},

	showTab: function (record) {
		if (! this.availZones[record.get('cloud_location')]) {
			this.loadMask.show();

			Ext.Ajax.request({
				url: '/server/ajax-ui-server-aws-ec2.php',
				params: { action: 'GetAvailZonesList', Region: record.get('cloud_location') },
				success: function(response, options) {
					var result = Ext.decode(response.responseText);

					if (result.data)
						this.availZones[record.get('cloud_location')] = result.data;

					this.loadMask.hide();
					this.loaded = true;
					this.showTab.call(this, record);
				},
				scope: this
			});
		} else {
			var settings = record.get('settings');

			if (settings['lb.use_elb'] == 1) {
				this.findOne('name', 'lb.use_elb').expand();
			} else {
				this.findOne('name', 'lb.use_elb').collapse();
			}

			this.findOne('name', 'lb.healthcheck.healthythreshold').setValue(settings['lb.healthcheck.healthythreshold'] || 3);
			this.findOne('name', 'lb.healthcheck.interval').setValue(settings['lb.healthcheck.interval'] || 30);
			this.findOne('name', 'lb.healthcheck.target').setValue(settings['lb.healthcheck.target'] || '');
			this.findOne('name', 'lb.healthcheck.timeout').setValue(settings['lb.healthcheck.timeout'] || 5);
			this.findOne('name', 'lb.healthcheck.unhealthythreshold').setValue(settings['lb.healthcheck.unhealthythreshold'] || 5);

			var avail = this.findOne('itemId', 'lb.avail_zone'), items = this.availZones[record.get('cloud_location')];
			avail.removeAll();
			for (var i = 0; i < items.length; i++) {
				var n = 'lb.avail_zone.' + items[i].id;
				avail.add({
					xtype: 'checkbox',
					name: n,
					boxLabel: items[i].name,
					hideLabel: true,
					checked: (settings[n] || 0) == 1? true : false
				});
			}

			avail.doLayout(false, true);

			var tbar = this.findOne('itemId', 'listeners').getBottomToolbar();
			tbar.getComponent('lb.bbar.proto').reset();
			tbar.getComponent('lb.bbar.lb_port').reset();
			tbar.getComponent('lb.bbar.i_port').reset();

			var data = [];
			for (i in settings) {
				if (i.indexOf('lb.role.listener.') != -1) {
					var lst = settings[i].split('#');
					data[data.length] = {
						protocol: lst[0],
						lb_port: lst[1],
						instance_port: lst[2]
					};
				}
			}

			this.findOne('itemId', 'listeners_view').store.loadData(data);

			if (settings['lb.hostname'])
				this.findOne('itemId', 'listeners').disable();
			else
				this.findOne('itemId', 'listeners').enable();
		}
	},

	hideTab: function (record) {
		var settings = record.get('settings'), avail = this.findOne('itemId', 'lb.avail_zone');

		if (! this.findOne('name', 'lb.use_elb').collapsed) {
			settings['lb.use_elb'] = 1;

			settings['lb.healthcheck.healthythreshold'] = this.findOne('name', 'lb.healthcheck.healthythreshold').getValue();
			settings['lb.healthcheck.interval'] = this.findOne('name', 'lb.healthcheck.interval').getValue();
			settings['lb.healthcheck.target'] = this.findOne('name', 'lb.healthcheck.target').getValue();
			settings['lb.healthcheck.timeout'] = this.findOne('name', 'lb.healthcheck.timeout').getValue();
			settings['lb.healthcheck.unhealthythreshold'] = this.findOne('name', 'lb.healthcheck.unhealthythreshold').getValue();

			avail.items.each(function (item) {
				settings[item.name] = item.checked ? 1 : 0;
			});

			for (i in settings) {
				if (i.indexOf('lb.role.listener.') != -1)
					delete settings[i];
			}

			this.findOne('itemId', 'listeners_view').store.each(function (rec) {
				settings['lb.role.listener.' + rec.id] = [ rec.get('protocol'), rec.get('lb_port'), rec.get('instance_port') ].join("#");
			});

		} else {
			settings['lb.use_elb'] = 0;
		}

		record.set('settings', settings);
	},

	items: [{
		xtype: 'fieldset',
		title: 'Use <a target="_blank" href="http://aws.amazon.com/elasticloadbalancing/">Amazon Elastic Load Balancer</a> to balance load between instances of this role',
		name: 'lb.use_elb',
		checkboxToggle: true,
		labelWidth: 120,
		items: [{
			xtype: 'fieldset',
			title: 'Threshold',
			labelWidth: 140,
			items: [{
				xtype: 'compositefield',
				fieldLabel: 'Healthy Threshold',
				items: [{
					xtype: 'textfield',
					name: 'lb.healthcheck.healthythreshold',
					width: 40
				}, {
					xtype: 'displayfield',
					name: 'lb.healthcheck.healthythreshold_help',
					value: '<img src="/images/ui-ng/icons/info_icon_16x16.png" style="padding: 2px; cursor: help;">'
				}]
			}, {
				xtype: 'compositefield',
				fieldLabel: 'Interval',
				items: [{
					xtype: 'textfield',
					name: 'lb.healthcheck.interval',
					width: 40
				}, {
					xtype: 'displayfield',
					cls: 'x-form-check-wrap',
					value: 'seconds'
				}, {
					xtype: 'displayfield',
					name: 'lb.healthcheck.interval_help',
					value: '<img src="/images/ui-ng/icons/info_icon_16x16.png" style="padding: 2px; cursor: help;">'
				}]
			}, {
				xtype: 'compositefield',
				fieldLabel: 'Target',
				items: [{
					xtype: 'textfield',
					name: 'lb.healthcheck.target',
					width: 200
				}, {
					xtype: 'displayfield',
					name: 'lb.healthcheck.target_help',
					value: '<img src="/images/ui-ng/icons/info_icon_16x16.png" style="padding: 2px; cursor: help;">'
				}]
			}, {
				xtype: 'compositefield',
				fieldLabel: 'Timeout',
				items: [{
					xtype: 'textfield',
					name: 'lb.healthcheck.timeout',
					width: 40
				}, {
					xtype: 'displayfield',
					cls: 'x-form-check-wrap',
					value: 'seconds'
				}, {
					xtype: 'displayfield',
					name: 'lb.healthcheck.timeout_help',
					value: '<img src="/images/ui-ng/icons/info_icon_16x16.png" style="padding: 2px; cursor: help;">'
				}]
			}, {
				xtype: 'compositefield',
				fieldLabel: 'Unhealthy Threshold',
				items: [{
					xtype: 'textfield',
					name: 'lb.healthcheck.unhealthythreshold',
					width: 40
				}, {
					xtype: 'displayfield',
					name: 'lb.healthcheck.unhealthythreshold_help',
					value: '<img src="/images/ui-ng/icons/info_icon_16x16.png" style="padding: 2px; cursor: help;">'
				}]
			}]
		}, {
			xtype: 'fieldset',
			title: 'Availability zones',
			itemId: 'lb.avail_zone'
		}, {
			title: 'Listeners',
			itemId: 'listeners',
			items: new Scalr.Viewers.list.ListView({
				store: new Ext.data.JsonStore({
					fields: [ 'protocol', 'lb_port', 'instance_port' ]
				}),
				itemId: 'listeners_view',
				autoHeight: true,
				emptyText: "No listeners defined",
				deferEmptyText: false,
				actionColumnPlugin: true,
				columns: [
					{ header: "Protocol", width: '150px', sortable: true, dataIndex: 'protocol', hidden: 'no' },
					{ header: "Load balancer port", width: '180px', sortable: true, dataIndex: 'lb_port', hidden: 'no' },
					{ header: "Instance port", width: '180px', sortable: true, dataIndex: 'instance_port', hidden: 'no' },
					{ header: "&nbsp;", width: '20px', sortable: false, dataIndex: 'id', align:'center', hidden: 'no',
						tpl: '<img src="/images/ui-ng/icons/delete_icon_16x16.png">', clickHandler: function (comp, store, record) {
							store.remove(record);
						}
					}
				]
			}),
			bbar: [
				'Protocol:', {
					xtype: 'combo',
					itemId: 'lb.bbar.proto',
					editable: false,
					store: [ 'TCP', 'HTTP' ],
					mode: 'local',
					triggerAction: 'all',
					width: 60,
					listWidth: 60,
					allowBlank: false
				}, '&nbsp;','Load balancer port:&nbsp;', {
					xtype: 'textfield',
					itemId: 'lb.bbar.lb_port',
					width: 75,
					allowBlank: false,
					validator: function (value) {
						if (value < 1024 || value > 65535) {
							if (value != 80 && value != 443)
								return 'Valid LoadBalancer ports are - 80, 443 and 1024 through 65535';
						}

						return true;
					}
				}, '&nbsp;&nbsp;&nbsp;Instance port:&nbsp;', {
					xtype: 'textfield',
					itemId: 'lb.bbar.i_port',
					width: 75,
					allowBlank: false,
					validator: function (value) {
						if (value < 1 || value > 65535)
							return 'Valid instance ports are one (1) through 65535';
						else
							return true;
					}
				}, '&nbsp;', {
					itemId: 'lb.bbar.add',
					icon: '/images/add.png', // icons can also be specified inline
					cls: 'x-btn-icon',
					tooltip: 'Add new listener'
				}
			]
		}]
	}]
})
{/literal}