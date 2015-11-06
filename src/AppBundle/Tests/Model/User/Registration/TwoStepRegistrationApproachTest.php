<?php

/*
 * This file is part of the Sententiaregum project.
 *
 * (c) Maximilian Bosch <maximilian.bosch.27@gmail.com>
 * (c) Ben Bieler <benjaminbieler2014@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
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
            ->willReturn(
                new ConstraintViolationList(
                    [
                        new ConstraintViolation('Invalid username!', 'Invalid username!', [], 'username', 'username', 'Ma27'),
                        new ConstraintViolation('Invalid username!', 'Invalid username!', [], 'username', 'username', 'Ma27', null, UniqueProperty::NON_UNIQUE_PROPERTY),
                    ]
                )
            );

        $em = $this->getMock(EntityManagerInterface::class);

        $suggestor = $this->getMockWithoutInvokingTheOriginalConstructor(ChainSuggestor::class);
        $suggestor
            ->expects($this->once())
            ->method('getPossibleSuggestions')
            ->willReturn(['Ma_27']);

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

        $repository = $this->getMockWithoutInvokingTheOriginalConstructor(EntityRepository::class);
        $repository
            ->expects($this->at(1))
            ->method('findOneBy')
            ->with(['username' => 'Ma27'])
            ->willReturn(User::create('Ma27', '123456', 'Ma27@sententiaregum.dev'));

        $repository
            ->expects($this->at(0))
            ->method('findOneBy')
            ->with(['role' => 'ROLE_USER'])
            ->willReturn(new Role('ROLE_USER'));

        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->willReturn($repository);

        $generator = $this->getMock(ActivationKeyCodeGeneratorInterface::class);
        $generator
            ->expects($this->any())
            ->method('generate')
            ->with(255)
            ->willReturn(str_repeat('X', 255));

        $dispatcher = $this->getMock(EventDispatcherInterface::class);
        $dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(MailerEvent::EVENT_NAME);

        $hasher = $this->getPasswordHasher();
        $hasher
            ->expects($this->once())
            ->method('generateHash')
            ->willReturnArgument(0);

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

        $repository = $this->getMockWithoutInvokingTheOriginalConstructor(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['activationKey' => $key, 'username' => 'Ma27'])
            ->willReturn(null);

        $entityManager = $this->getMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->willReturn($repository);

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

        $repository = $this->getMockWithoutInvokingTheOriginalConstructor(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['activationKey' => $key, 'username' => 'Ma27'])
            ->willReturn($user);

        $entityManager = $this->getMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->willReturn($repository);

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
            ->willReturn(new ConstraintViolationList());

        $validatorMock
            ->expects($this->exactly(200))
            ->method('validate')
            ->willReturn(
                new ConstraintViolationList(
                    [new ConstraintViolation('Invalid api key!', 'Invalid api key!', [], null, 'apiKey', '123456')]
                )
            );

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

        $repository = $this->getMockWithoutInvokingTheOriginalConstructor(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['activationKey' => $key, 'username' => 'Ma27'])
            ->willReturn($user);

        $entityManager = $this->getMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->willReturn($repository);

        $entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($user);

        $entityManager
            ->expects($this->once())
            ->method('flush')
            ->with($user);

        $provider = $this->getMock(ExpiredActivationProviderInterface::class);
        $provider
            ->expects($this->any())
            ->method('checkApprovalByActivationKey')
            ->willReturn(false);

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
            ->method('checkApprovalByUser')
            ->willReturn(true);

        return $provider;
    }
}
