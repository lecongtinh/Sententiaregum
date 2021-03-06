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

import InfoBox from '../../../../components/portal/login/InfoBox';
import TestUtils from 'react/lib/ReactTestUtils';
import React from 'react';
import { expect } from 'chai';

describe('InfoBox', () => {
  it('renders infobox', () => {
    const renderer = TestUtils.createRenderer();
    renderer.render(<InfoBox />);
    const output  = renderer.getRenderOutput();

    expect(output.props.children.props.className).to.equal('info-div-text');
    expect(output.props.children.props.children.props.content).to.equal('pages.portal.login.info_text');
  })
});
