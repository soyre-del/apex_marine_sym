<?php

namespace App\Form;

use App\Entity\User;
use App\Security\CredentialSecurityPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'constraints' => [
                    new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                        $error = CredentialSecurityPolicy::validateUsername(is_string($value) ? $value : '');
                        if ($error !== null) {
                            $context->buildViolation($error)->addViolation();
                        }
                    }),
                ],
                'attr' => ['autocomplete' => 'username', 'maxlength' => 50],
            ])
            ->add('firstName', TextType::class, [
                'constraints' => [
                    new NotBlank(message: 'Please enter your first name.'),
                    new Length(
                        min: 2,
                        max: 100,
                        minMessage: 'First name must be at least {{ limit }} characters.',
                    ),
                    new Regex(
                        pattern: '/^[\p{L}\s\'.-]+$/u',
                        message: 'First name may only contain letters, spaces, hyphens, and apostrophes.',
                    ),
                ],
                'attr' => ['autocomplete' => 'given-name', 'maxlength' => 100],
            ])
            ->add('middleName', TextType::class, [
                'required' => false,
                'constraints' => [
                    new Length(max: 100),
                    new Regex(
                        pattern: '/^[\p{L}\s\'.-]*$/u',
                        message: 'Middle name may only contain letters, spaces, hyphens, and apostrophes.',
                    ),
                ],
                'attr' => ['autocomplete' => 'additional-name', 'maxlength' => 100],
            ])
            ->add('surname', TextType::class, [
                'constraints' => [
                    new NotBlank(message: 'Please enter your surname.'),
                    new Length(
                        min: 2,
                        max: 100,
                        minMessage: 'Surname must be at least {{ limit }} characters.',
                    ),
                    new Regex(
                        pattern: '/^[\p{L}\s\'.-]+$/u',
                        message: 'Surname may only contain letters, spaces, hyphens, and apostrophes.',
                    ),
                ],
                'attr' => ['autocomplete' => 'family-name', 'maxlength' => 100],
            ])
            ->add('email', EmailType::class, [
                'constraints' => [
                    new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                        $error = CredentialSecurityPolicy::validateEmail(is_string($value) ? $value : '');
                        if ($error !== null) {
                            $context->buildViolation($error)->addViolation();
                        }
                    }),
                ],
                'attr' => [
                    'autocomplete' => 'email',
                    'maxlength' => 100,
                    'pattern' => '^[a-zA-Z0-9](?:[a-zA-Z0-9._%+-]*[a-zA-Z0-9])?@(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,24}$',
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(
                        message: 'You must agree to the terms and conditions.',
                    ),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'minlength' => 8,
                    'maxlength' => 128,
                ],
                'constraints' => [
                    new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                        $rootData = $context->getRoot()->getData();
                        $email = $rootData instanceof User ? $rootData->getEmail() : null;
                        $error = CredentialSecurityPolicy::validatePassword(
                            is_string($value) ? $value : '',
                            $email,
                        );
                        if ($error !== null) {
                            $context->buildViolation($error)->addViolation();
                        }
                    }),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
