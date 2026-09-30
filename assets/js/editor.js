( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var registerPlugin = wp.plugins.registerPlugin;
	var __ = wp.i18n.__;

	var PluginSidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editPost && wp.editPost.PluginSidebar );
	var PluginSidebarMoreMenuItem =
		( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem );

	if ( ! PluginSidebar ) {
		return;
	}

	var cfg = window.AgentSteamerLangEditor || {};
	var NS = '/agentsteamer-lang/v1';
	var TAX = cfg.taxonomy || 'asl_language';
	var LANGS = cfg.languages || [];
	var DEFAULT = cfg.default || '';

	if ( wp.apiFetch && wp.apiFetch.createNonceMiddleware && cfg.nonce ) {
		wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( cfg.nonce ) );
	}

	var c = wp.components;
	var SelectControl = c.SelectControl;
	var Button = c.Button;
	var PanelBody = c.PanelBody;
	var Spinner = c.Spinner;
	var Notice = c.Notice;

	var SIDEBAR = 'agentsteamer-lang-sidebar';

	function SidebarInner() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var termIds = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( TAX ) || [];
		}, [ TAX ] );
		var editPost = useDispatch( 'core/editor' ).editPost;

		var busyPair = useState( '' );
		var busy = busyPair[ 0 ];
		var setBusy = busyPair[ 1 ];
		var msgPair = useState( null );
		var msg = msgPair[ 0 ];
		var setMsg = msgPair[ 1 ];
		var trPair = useState( {} );
		var translations = trPair[ 0 ];
		var setTranslations = trPair[ 1 ];
		var loadedPair = useState( false );
		var loaded = loadedPair[ 0 ];
		var setLoaded = loadedPair[ 1 ];
		var allBusyPair = useState( false );
		var allBusy = allBusyPair[ 0 ];
		var setAllBusy = allBusyPair[ 1 ];
		var allMsgPair = useState( null );
		var allMsg = allMsgPair[ 0 ];
		var setAllMsg = allMsgPair[ 1 ];

		function currentTermId() {
			return termIds && termIds.length ? termIds[ 0 ] : 0;
		}

		function currentCode() {
			var id = currentTermId();
			for ( var i = 0; i < LANGS.length; i++ ) {
				if ( LANGS[ i ].termId === id ) {
					return LANGS[ i ].code;
				}
			}
			return '';
		}

		function loadTranslations() {
			if ( ! postId ) {
				return;
			}
			wp.apiFetch( { path: NS + '/translations?post_id=' + postId } )
				.then( function ( res ) {
					setTranslations( res.translations || {} );
					setLoaded( true );
				} )
				.catch( function () {
					setLoaded( true );
				} );
		}

		useEffect( loadTranslations, [ postId ] );

		function saveThen( run ) {
			var select = wp.data.select( 'core/editor' );
			var dispatch = wp.data.dispatch( 'core/editor' );
			if ( ! select.isEditedPostDirty() && ! select.isSavingPost() ) {
				run();
				return;
			}
			dispatch.savePost();
			var tries = 0;
			var timer = window.setInterval( function () {
				tries++;
				if ( ! select.isSavingPost() || tries > 80 ) {
					window.clearInterval( timer );
					window.setTimeout( run, 300 );
				}
			}, 250 );
		}

		// Background translation job: queue on the server, then translate one
		// language per request via polling. `channel` routes progress to the
		// right panel: 'all' = 一键翻译 panel, 'lang' = 语言稿 panel.
		function finishJob( s, channel ) {
			var failed = Object.keys( s.errors || {} );
			var text = __( '完成：', 'agentsteamer-lang' ) + ( s.done || [] ).length + __( ' 种语言', 'agentsteamer-lang' );
			if ( failed.length ) {
				var why = s.errors[ failed[ 0 ] ] ? '（' + s.errors[ failed[ 0 ] ] + '）' : '';
				text += '，' + failed.length + __( ' 种失败', 'agentsteamer-lang' ) + '（' + failed.join( ', ' ) + why + '）';
			}
			var payload = { text: text, review: s.review_url || cfg.reviewUrl };
			if ( channel === 'all' ) {
				setAllMsg( payload );
				setAllBusy( false );
			} else {
				setMsg( payload );
			}
			setBusy( '' );
			loadTranslations();
		}

		function stepLoop( channel ) {
			wp.apiFetch( { path: NS + '/translate-step', method: 'POST', data: { post_id: postId } } )
				.then( function ( s ) {
					if ( s.state === 'running' ) {
						var progress = { text: __( '翻译中：', 'agentsteamer-lang' ) + ( s.done || [] ).length + '/' + ( s.total || 0 ) + '…' };
						if ( channel === 'all' ) {
							setAllMsg( progress );
						} else {
							setMsg( progress );
						}
						window.setTimeout( function () {
							stepLoop( channel );
						}, 800 );
						return;
					}
					finishJob( s, channel );
				} )
				.catch( function () {
					// A long language may exceed the gateway timeout; keep polling
					// (the unfinished language stays queued and is retried).
					var note = { text: __( '持续处理中…', 'agentsteamer-lang' ) };
					if ( channel === 'all' ) {
						setAllMsg( note );
					} else {
						setMsg( note );
					}
					window.setTimeout( function () {
						stepLoop( channel );
					}, 3000 );
				} );
		}

		function startJob( targets, channel ) {
			wp.apiFetch( {
				path: NS + '/translate-all',
				method: 'POST',
				data: { post_id: postId, source: currentCode(), targets: targets || [] }
			} )
				.then( function () {
					var started = { text: __( '已开始翻译…', 'agentsteamer-lang' ) };
					if ( channel === 'all' ) {
						setAllMsg( started );
					} else {
						setMsg( started );
					}
					stepLoop( channel );
				} )
				.catch( function ( err ) {
					var payload = { text: ( err && err.message ) || __( '翻译失败', 'agentsteamer-lang' ) };
					if ( channel === 'all' ) {
						setAllMsg( payload );
						setAllBusy( false );
					} else {
						setMsg( payload );
					}
					setBusy( '' );
				} );
		}

		function translateAll() {
			if ( ! currentCode() ) {
				setAllMsg( { text: __( '请先选择本文语言。', 'agentsteamer-lang' ) } );
				return;
			}
			if ( ! postId ) {
				setAllMsg( { text: __( '请先保存草稿。', 'agentsteamer-lang' ) } );
				return;
			}
			setAllBusy( true );
			setAllMsg( { text: __( '正在保存…', 'agentsteamer-lang' ) } );
			saveThen( function () {
				startJob( [], 'all' );
			} );
		}

		function setLanguage( code ) {
			var termId = 0;
			for ( var i = 0; i < LANGS.length; i++ ) {
				if ( LANGS[ i ].code === code ) {
					termId = LANGS[ i ].termId;
				}
			}
			var attr = {};
			attr[ TAX ] = termId ? [ termId ] : [];
			editPost( attr );
			setMsg( null );
		}

		function createTranslation( code ) {
			if ( ! postId ) {
				setMsg( { text: __( '请先保存草稿，再创建语言稿。', 'agentsteamer-lang' ) } );
				return;
			}
			setBusy( code );
			setMsg( { text: __( '正在保存并翻译…', 'agentsteamer-lang' ) } );
			saveThen( function () {
				startJob( [ code ], 'lang' );
			} );
		}

		var current = currentCode();

		var options = [ { label: __( '（未指定）', 'agentsteamer-lang' ), value: '' } ].concat(
			LANGS.map( function ( l ) {
				return { label: l.name + ( l.code === DEFAULT ? ' ★' : '' ), value: l.code };
			} )
		);

		var rows = LANGS.map( function ( l ) {
			var existing = translations[ l.code ];
			var isCurrent = l.code === current;
			return el(
				'li',
				{ key: l.code, className: 'asl-editor-tr' },
				el( 'span', { className: 'asl-flag-code' }, l.code.toUpperCase() ),
				isCurrent ? el( 'em', null, __( '（本文）', 'agentsteamer-lang' ) ) : null,
				existing && ! isCurrent
					? el( 'a', { href: existing.url, target: '_blank', rel: 'noopener' }, __( '查看', 'agentsteamer-lang' ) )
					: null,
				! isCurrent && ! existing
					? el(
						'span',
						{ className: 'asl-editor-actions' },
						el( Button, { variant: 'primary', isSmall: true, disabled: busy === l.code || ! cfg.hasProvider || ! cfg.aiEnabled, onClick: function () { createTranslation( l.code ); } },
							busy === l.code ? el( Spinner, null ) : __( '创建AI翻译副本', 'agentsteamer-lang' )
						)
					)
					: null
			);
		} );

		return el(
			PluginSidebar,
			{ name: SIDEBAR, title: __( 'AgentSteamer 语言', 'agentsteamer-lang' ), icon: 'translation' },
			el(
				PanelBody,
				{ title: __( '语言', 'agentsteamer-lang' ), initialOpen: true },
				el( SelectControl, {
					label: __( '本文语言', 'agentsteamer-lang' ),
					value: current,
					options: options,
					onChange: setLanguage
				} ),
				el( 'p', { className: 'asl-hint' }, __( '保存文章后语言生效。', 'agentsteamer-lang' ) )
			),
			el(
				PanelBody,
				{ title: __( '一键翻译', 'agentsteamer-lang' ), initialOpen: true },
				el( 'p', { className: 'asl-hint' }, __( '以「本文语言」为源，调用大模型把文章翻译为其它所有已配置语言（生成草稿并进入审阅队列）。', 'agentsteamer-lang' ) ),
				el( Button, { variant: 'primary', disabled: allBusy || ! cfg.hasProvider || ! cfg.aiEnabled, onClick: translateAll },
					allBusy ? el( Spinner, null ) : __( '一键翻译为全部语言', 'agentsteamer-lang' )
				),
				( ! cfg.hasProvider || ! cfg.aiEnabled )
					? el( 'p', { className: 'asl-hint' }, __( '尚未配置可用的大模型接口，请前往「AgentSteamer Lang → 设置」配置。', 'agentsteamer-lang' ) )
					: null,
				allMsg
					? el( Notice, { status: 'success', isDismissible: false }, [
						allMsg.text,
						allMsg.review ? el( 'a', { key: 'rv', href: allMsg.review, target: '_blank', rel: 'noopener' }, ' ' + __( '前往审阅', 'agentsteamer-lang' ) ) : null
					] )
					: null
			),
			el(
				PanelBody,
				{ title: __( '语言稿', 'agentsteamer-lang' ), initialOpen: true },
				! loaded ? el( Spinner, null ) : null,
				el( 'ul', { className: 'asl-editor-translations' }, rows ),
				el( Button, { variant: 'tertiary', isSmall: true, onClick: loadTranslations }, __( '刷新', 'agentsteamer-lang' ) ),
				msg
					? el( Notice, { status: 'success', isDismissible: false }, [
						msg.text,
						msg.review ? el( 'a', { key: 'r', href: msg.review, target: '_blank', rel: 'noopener' }, ' ' + __( '前往审阅', 'agentsteamer-lang' ) ) : null,
						msg.url ? el( 'a', { key: 'e', href: msg.url, target: '_blank', rel: 'noopener' }, ' ' + __( '编辑语言稿', 'agentsteamer-lang' ) ) : null
					] )
					: null
			)
		);
	}

	function Sidebar() {
		return el(
			Fragment,
			null,
			PluginSidebarMoreMenuItem
				? el( PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: 'translation' }, __( 'AgentSteamer 语言', 'agentsteamer-lang' ) )
				: null,
			el( SidebarInner, null )
		);
	}

	registerPlugin( 'agentsteamer-lang', { render: Sidebar } );
} )( window.wp );
