{include file="inc/header.tpl"}
<link rel="stylesheet" href="css/grids.css" type="text/css" />
<div id="maingrid-ct" class="ux-gridviewer"></div>
<script type="text/javascript">

var uid = '{$smarty.session.uid}';

var regions = [
{section name=id loop=$regions}
	['{$regions[id]}','{$regions[id]}']{if !$smarty.section.id.last},{/if}
{/section}
];

var region = '{$smarty.session.aws_region}';

{literal}
Ext.onReady(function () {

Ext.QuickTips.init();
	// create the Data Store
    var store = new Ext.ux.scalr.Store({
    	reader: new Ext.ux.scalr.JsonReader({
	        root: 'data',
	        successProperty: 'success',
	        errorProperty: 'error',
	        totalProperty: 'total',
	        id: 'id',
	        	
	        fields: [
				'id','engine','name','description'
	        ]
    	}),
    	remoteSort: true,
		url: '/server/grids/aws_rds_param_groups_list.php?a=1{/literal}{$grid_query_string}{literal}',
		listeners: { dataexception: Ext.ux.dataExceptionReporter }
    });
		
    var renderers = Ext.ux.scalr.GridViewer.columnRenderers;
	var grid = new Ext.ux.scalr.GridViewer({
        renderTo: "maingrid-ct",
        height: 500,
        title: "Parameter groups",
        id: 'paramgroups_list_'+GRID_VERSION,
        store: store,
        maximize: true,
        viewConfig: { 
        	emptyText: "No db parameter groups found"
        },

        enableFilter: false,

        tbar: 
        [ 
			 {
				icon: '/images/add.png', // icons can also be specified inline
				cls: 'x-btn-icon',
				tooltip: 'Add new parameter group',
				handler: function()
				{
					document.location.href = '/aws_rds_param_group_add.php';
				}
			 }
	    ], 
		
        // Columns
        columns:[
			{header: "Name", width: 70, dataIndex: 'name', sortable: true},
			{header: "Description", width: 50, dataIndex: 'description', sortable: false}
		],
		
    	// Row menu
    	rowOptionsMenu: [
      	             	
			{id: "option.edit", 		text:'Edit', 			  	href: "/aws_rds_param_group_edit.php?name={name}"},
			new Ext.menu.Separator({id: "option.editSep"}),
			{id: "option.events",       text: 'Events log', href: "/aws_rds_events_log.php?type=db-parameter-group&name={name}"}
     	],
     	getRowOptionVisibility: function (item, record) {

			return true;
		},
		withSelected: {
			menu: [
				{text: "Delete", value: "delete"}
			],
			hiddens: {with_selected : 1},
			action: "act"
		}
    });
    
    grid.render();
    store.load();

	return;
});
{/literal}
</script>
{include file="inc/footer.tpl"}