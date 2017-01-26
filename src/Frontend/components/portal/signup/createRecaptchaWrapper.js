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

import React, { Component, PropTypes } from 'react';
import Recaptcha                       from 'react-recaptcha';

/**
 * Simple HOC which builds a wrapper for the reCAPTCHA API.
 *
 * @param {String} siteKey The siteKey of the captcha.
 *
 * @returns {React.Component} The react component used for rendering the recaptcha.
 */
export default siteKey => class extends Component {
  static propTypes = {
    input:   PropTypes.object,
    success: PropTypes.bool
  };

  static contextTypes = {
    store: PropTypes.object
  };

  /**
   * Disables the component update to avoid any re-rendering process of the reCAPTCHA field.
   *
   * @returns {boolean} False to disable update.
   */
  shouldComponentUpdate() {
    return false;
  }

  /**
   * Lifecycle hook to reset the reCAPTCHA in case of changes in the parent component.
   *
   * @param {Object} next The new properties.
   *
   * @returns {void}
   */
  componentWillReceiveProps(next) {
    /*if (!next.success) {
      this._resetCaptcha();
    }*/
  }

  /**
   * Renders the full DOM.
   *
   * @returns {XML} The DOM structure.
   */
  render() {
    return <Recaptcha
      sitekey={siteKey}
      render='explicit'
      onloadCallback={() => {}}
      ref={e => this.recaptcha = e}
      verifyCallback={res => this.props.input.onChange(res)}
      expiredCallback={() => this._resetCaptcha()}
    />;
  }

  /**
   * Resets the rendered recaptcha.
   *
   * @returns {void}
   * @private
   */
  _resetCaptcha() {
    if (this.recaptcha) {
      this.recaptcha.reset();
    }
  }
};