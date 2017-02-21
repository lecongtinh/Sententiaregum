/*
 * This file is part of the Sententiaregum project.
 *
 * (c) Maximilian Bosch <maximilian@mbosch.me>
 * (c) Ben Bieler <ben@benbieler.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

'use strict';

import React, { PropTypes }     from 'react';
import MenuWrapper              from './menu/MenuWrapper';
import Menu                     from './menu/Menu';
import { connect }              from 'react-redux';
import { bindActionCreators }   from 'redux';
import *  as menuActions        from '../../../actions/menuActions';
import *  as localeActions      from '../../../actions/localeActions';

const AppMenu = ({ items, actions }) =>
  <MenuWrapper actions={actions.locale}>
    <Menu items={items} actions={actions.menu} />
  </MenuWrapper>;


AppMenu.propTypes = {
  items:   PropTypes.arrayOf(React.PropTypes.object),
  actions: PropTypes.object
};

const mapStateToProps = state => ({
  items: state.menu
});

const mapDispatchToProps = dispatch => ({
  actions: {
    menu:   bindActionCreators(menuActions, dispatch),
    locale: bindActionCreators(localeActions, dispatch)
  }
});

export default connect(
  mapStateToProps,
  mapDispatchToProps,
  null,

  // the Menu tree depends on the router passed through the react tree context.
  // If some changes (e.g. a route change) happen, a re-rendering is necessary.
  // In this the only re-rendered thing is the menu bar, so no performance flaw is expected.
  { pure: false }
)(AppMenu);
