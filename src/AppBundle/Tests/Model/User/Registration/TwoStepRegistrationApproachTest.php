<?php

/*
 * This file is part of the sententiaregum application.
 *
 * Sententiaregum is a social network based on Symfony2 and BackboneJS/ReactJS
 *
 * @copyright (c) 2015 Sententiaregum
 * Please check out the license file in the document root of this application
 */

namespace AppBundle\Tests\Model\User\Registration;

use AppBundle\Event\MailerEvent;
use AppBundle\Model\User\Registration\Activation\ExpiredActivationProviderInterface;
use AppBundle\Model\User\Registration\DTO\CreateUserDTO;
use AppBundle\Model\User\Registration\Generator\ActivationKeyCodeGeneratorInterface;
use AppBundle\Model\User\Registration\NameSuggestion\ChainSuggestor;
use AppBundle\Model\User\Registration\TwoStepRegistrationApproach;
use AppBundle\Model\User\Registration\Value\Result;
use AppBundle\Model\User\Role;
use AppBundle\Model\User\User;
use AppBundle\Validator\Constraints\UniqueProperty;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Ma27\ApiKeyAuthenticationBundle\Model\Password\PasswordHasherInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TwoStepRegistrationApproachTest extends \PHPUnit_Framework_TestCase
{
    public function testCreateInvalidUser()
    {
        $dto = new CreateUserDTO();
        $dto->setUsername('Ma27');
        $dto->setPassword('123456');
        $dto->setEmail('Ma27@sententiaregum.dev');

        $validatorMock = $this->getMock(ValidatorInterface::class);
        $validatorMock
            ->expects($this->any())
            ->method('validate')
            ->will($this->returnValue(
                new ConstraintViolationList(
                    [
                        new ConstraintViolation('Invalid username!', 'Invalid username!', [], 'username', 'username', 'Ma27'),
                        new ConstraintViolation('Invalid username!', 'Invalid username!', [], 'username', 'username', 'Ma27', null, UniqueProperty::NON_UNIQUE_PROPERTY),
                    ]
                )
            ));

        $em = $this->getMock(EntityManagerInterface::class);

        $suggestor = $this->getMockBuilder(ChainSuggestor::class)->disableOriginalConstructor()->getMock();
        $suggestor
            ->expects($this->once())
            ->method('getPossibleSuggestions')
            ->will($this->returnValue(['Ma_27']));

        $registration = new TwoStepRegistrationApproach(
            $em,
            $this->getMock(ActivationKeyCodeGeneratorInterface::class),
            $validatorMock,
            $this->getMock(EventDispatcherInterface::class),
            $this->getMock(PasswordHasherInterface::class),
            $suggestor,
            $this->getActivationProvider()
        );

        $result = $registration->registration($dto);
        $this->assertInstanceOf(Result::class, $result);

        $this->assertFalse($result->isValid());
        $this->assertCount(2, $result->getViolations());
        $this->assertCount(1, $result->getSuggestions());
    }

    public function testCreateUser()
    {
        $dto = new CreateUserDTO();
        $dto->setUsername('Ma27');
        $dto->setPassword('123456');
        $dto->setEmail('Ma27@sententiaregum.dev');

        $entityManager = $this->getMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('persist');

        $entityManager
            ->expects($this->once())
            ->method('flush');

        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository
            ->expects($this->at(1))
            ->method('findOneBy')
            ->with(['username' => 'Ma27'])
            ->will($this->returnValue(User::create('Ma27', '123456', 'Ma27@sententiaregum.dev')));

        $repository
            ->expects($this->at(0))
            ->method('findOneBy')
            ->with(['role' => 'ROLE_USER'])
            ->will($this->returnValue(new Role('ROLE_USER')));

        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->will($this->returnValue($repository));

        $generator = $this->getMock(ActivationKeyCodeGeneratorInterface::class);
        $generator
            ->expects($this->any())
            ->method('generate')
            ->with(255)
            ->will($this->returnValue(str_repeat('X', 255)));

        $dispatcher = $this->getMock(EventDispatcherInterface::class);
        $dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(MailerEvent::EVENT_NAME);

        $hasher = $this->getPasswordHasher();
        $hasher
            ->expects($this->once())
            ->method('generateHash')
            ->will($this->returnArgument(0));

        $provider = $this->getActivationProvider();
        $provider
            ->expects($this->once())
            ->method('attachNewApproval');

        $registration = new TwoStepRegistrationApproach(
            $entityManager,
            $generator,
            $this->getMock(ValidatorInterface::class),
            $dispatcher,
            $hasher,
            new ChainSuggestor($entityManager),
            $provider
        );

        $result = $registration->registration($dto);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertInstanceOf(User::class, $result->getUser());
        $this->assertTrue($result->isValid());
        $this->assertNull($result->getViolations());
        $this->assertCount(0, $result->getSuggestions());
    }

    /**
     * @expectedException \AppBundle\Exception\UserActivationException
     */
    public function testInvalidActivationKey()
    {
        $key = md5(uniqid());

        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['activationKey' => $key, 'username' => 'Ma27'])
            ->will($this->returnValue(null));

        $entityManager = $this->getMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->will($this->returnValue($repository));

        $registration = new TwoStepRegistrationApproach(
            $entityManager,
            $this->getMock(ActivationKeyCodeGeneratorInterface::class),
            $this->getMock(ValidatorInterface::class),
            $this->getMock(EventDispatcherInterface::class),
            $this->getPasswordHasher(),
            new ChainSuggestor($entityManager),
            $this->getActivationProvider()
        );

        $registration->approveByActivationKey($key, 'Ma27');
    }

    public function testApproveUser()
    {
        $key  = md5(uniqid());
        $user = User::create('Ma27', '123456', 'Ma27@sententiaregum.dev');

        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['activationKey' => $key, 'username' => 'Ma27'])
            ->will($this->returnValue($user));

        $entityManager = $this->getMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->will($this->returnValue($repository));

        $readyUser = $user->setState(User::STATE_APPROVED);

        $entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($readyUser);

        $entityManager
            ->expects($this->once())
            ->method('flush')
            ->with($readyUser);

        $registration = new TwoStepRegistrationApproach(
            $entityManager,
            $this->getMock(ActivationKeyCodeGeneratorInterface::class),
            $this->getMock(ValidatorInterface::class),
            $this->getMock(EventDispatcherInterface::class),
            $this->getPasswordHasher(),
            new ChainSuggestor($entityManager),
            $this->getActivationProvider()
        );

        $registration->approveByActivationKey($key, 'Ma27');
    }

    /**
     * @expectedException \OverflowException
     * @expectedExceptionMessage Cannot generate activation key!
     */
    public function testActivationKeyGenerationFailure()
    {
        $dto = new CreateUserDTO();
        $dto->setUsername('Ma27');
        $dto->setPassword('123456');
        $dto->setEmail('Ma27@sententiaregum.dev');

        $validatorMock = $this->getMock(ValidatorInterface::class);
        $validatorMock
            ->expects($this->at(0))
            ->method('validate')
            ->will($this->returnValue(new ConstraintViolationList()));

        $validatorMock
            ->expects($this->exactly(200))
            ->method('validate')
            ->will($this->returnValue(
                new ConstraintViolationList(
                    [new ConstraintViolation('Invalid api key!', 'Invalid api key!', [], null, 'apiKey', '123456')]
                )
            ));

        $em = $this->getMock(EntityManagerInterface::class);

        $registration = new TwoStepRegistrationApproach(
            $em,
            $this->getMock(ActivationKeyCodeGeneratorInterface::class),
            $validatorMock,
            $this->getMock(EventDispatcherInterface::class),
            $this->getMock(PasswordHasherInterface::class),
            new ChainSuggestor($em),
            $this->getActivationProvider()
        );

        $registration->registration($dto);
    }

    /**
     * @expectedException \AppBundle\Exception\UserActivationException
     */
    public function testExpiredActivation()
    {
        $key  = md5(uniqid());
        $user = User::create('Ma27', '123456', 'Ma27@sententiaregum.dev');

        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['activationKey' => $key, 'username' => 'Ma27'])
            ->will($this->returnValue($user));

        $entityManager = $this->getMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->will($this->returnValue($repository));

        $provider = $this->getMock(ExpiredActivationProviderInterface::class);
        $provider
            ->expects($this->any())
            ->method('checkApprovalByActivationKey')
            ->will($this->returnValue(false));

        $registration = new TwoStepRegistrationApproach(
            $entityManager,
            $this->getMock(ActivationKeyCodeGeneratorInterface::class),
            $this->getMock(ValidatorInterface::class),
            $this->getMock(EventDispatcherInterface::class),
            $this->getPasswordHasher(),
            new ChainSuggestor($entityManager),
            $provider
        );

        $registration->approveByActivationKey($key, 'Ma27');
    }

    /**
     * Creates the password hasher mock.
     *
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getPasswordHasher()
    {
        return $this->getMock(PasswordHasherInterface::class);
    }

    /**
     * Creates the provider mock.
     *
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getActivationProvider()
    {
        $provider = $this->getMock(ExpiredActivationProviderInterface::class);
        $provider
            ->expects($this->any())
            ->method('checkApprovalByActivationKey')
            ->will($this->returnValue(true));

        return $provider;
    }
}